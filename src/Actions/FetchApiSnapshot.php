<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Exceptions\ApiFetchException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pages a remote API into one NDJSON snapshot in the staging area, then hands
 * that snapshot to the unchanged file pipeline through the `sync_files` ledger.
 */
final class FetchApiSnapshot
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly FilesystemManager $filesystems,
        private readonly HttpFactory $http,
        private readonly StageFile $stageFile,
        private readonly RecordSyncFile $recordFile,
        private readonly StartRun $startRun,
        private readonly FinalizeRun $finalizeRun,
        private readonly TrackWatermark $watermark,
    ) {}

    public function __invoke(ApiSyncDefinition $definition, bool $force = false): DiscoveryResult
    {
        // The same lock file discovery takes, so a scheduled fetch that overlaps
        // a running one is a recorded no-op rather than a duplicate snapshot.
        $lock = $this->cache->lock("data-sync:discover:{$definition->name()}", 300);
        $result = $lock->get(fn (): DiscoveryResult => $this->fetch($definition, $force));

        if (! $result instanceof DiscoveryResult) {
            return new DiscoveryResult([], 0, 0, true);
        }

        return $result;
    }

    private function fetch(ApiSyncDefinition $definition, bool $force): DiscoveryResult
    {
        $staging = $this->filesystems->disk(StagingArea::disk());
        $filename = $definition->name().'-'.now()->format('Ymd-His').'.ndjson';
        $temporaryPath = $this->stageFile->temporaryPath($definition->name(), $filename);
        $snapshot = $this->scratchStream();

        try {
            $pending = $this->page($definition, $this->watermark->current($definition->name()), $snapshot);
        } catch (Throwable $exception) {
            // Whatever pages did arrive are still the truthful record of what the
            // remote returned, so they are preserved rather than discarded.
            $this->store($staging, $temporaryPath, $snapshot);
            $this->reportFailure($definition, $staging, $temporaryPath, $filename, $exception);

            throw ApiFetchException::fetchFailed($definition->name(), $definition->endpoint(), $exception);
        }

        $this->store($staging, $temporaryPath, $snapshot);
        $checksum = $this->stageFile->finalize($staging, $temporaryPath, $definition->name(), $filename);

        $path = StagingArea::pathFor($definition->name(), $checksum, $filename);

        // Every empty fetch has the same checksum, so the ledger would skip every
        // one after the first. An empty fetch always gets its own completed run
        // instead, so a quiet feed shows up in sync:status and its watermark
        // advances through the same completed-run rule as any other fetch.
        $empty = $staging->size($path) === 0;

        if (! $force && ! $empty && $this->recordFile->findCompleted($definition->name(), $checksum) instanceof SyncFile) {
            $staging->delete($path);

            return new DiscoveryResult([], 1, 0);
        }

        return new DiscoveryResult(
            [$this->record($definition->name(), $checksum, $filename, $staging, $pending)],
            0,
            0,
        );
    }

    /**
     * Walks every page, appending each record to the snapshot as it arrives, and
     * returns the last non-null watermark a page reported.
     *
     * @param  resource  $snapshot
     */
    private function page(ApiSyncDefinition $definition, mixed $watermark, mixed $snapshot): mixed
    {
        $request = $definition->request($this->client($definition), $watermark);
        $url = $definition->endpoint();
        $pending = $watermark;
        $visited = [];

        while ($url !== null) {
            if (isset($visited[$url])) {
                throw ApiFetchException::pagingLoop($definition->name(), $url);
            }

            $visited[$url] = true;
            $response = $this->send($request, $url);
            $records = iterator_to_array($definition->records($response), preserve_keys: false);

            foreach ($records as $record) {
                fwrite($snapshot, json_encode($record, JSON_THROW_ON_ERROR)."\n");
            }

            // Each page sees only its own records, so a page with nothing to
            // report, such as an empty last page, keeps the value found so far.
            $pending = $definition->watermarkFrom($response, $records) ?? $pending;
            $url = $definition->nextPage($response);
        }

        return $pending;
    }

    private function send(PendingRequest $request, string $url): Response
    {
        // Guzzle's query option replaces whatever query string the URL carries,
        // so a next-page link's own parameters have to be handed to the request
        // instead of left on the URL. withOptions() lets them win over the ones
        // the definition set, which is what a next-page link is for.
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (clone $request)
            ->withOptions(['query' => $query])
            ->get(Str::before($url, '?'))
            // A single-attempt request is the one case Laravel's retry helper
            // leaves unthrown.
            ->throw();
    }

    private function client(ApiSyncDefinition $definition): PendingRequest
    {
        return $this->http
            ->timeout($definition->timeout())
            ->retry(
                // Laravel counts the first attempt and retries() counts only the
                // extra ones, so the two differ by one.
                $definition->retries() + 1,
                $definition->retryDelay(),
                // A rejected request stays rejected however often it is sent;
                // only a transport fault or a server fault is worth repeating.
                fn (Throwable $exception): bool => $this->isRetryable($exception),
            );
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status >= 500 || $status === 429;
    }

    /**
     * @return resource
     */
    private function scratchStream(): mixed
    {
        $snapshot = fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b');

        if (! is_resource($snapshot)) {
            throw new RuntimeException('Unable to open a scratch stream for the API snapshot.');
        }

        return $snapshot;
    }

    /**
     * @param  resource  $snapshot
     */
    private function store(Filesystem $staging, string $temporaryPath, mixed $snapshot): void
    {
        try {
            rewind($snapshot);

            if (! $staging->writeStream($temporaryPath, $snapshot)) {
                throw new RuntimeException("Unable to stage the API snapshot at [{$temporaryPath}].");
            }
        } finally {
            fclose($snapshot);
        }
    }

    /**
     * Records whatever the fetch managed to write, so the failure is visible in
     * `sync:status` and the bytes reach the failed area.
     */
    private function reportFailure(
        ApiSyncDefinition $definition,
        Filesystem $staging,
        string $temporaryPath,
        string $filename,
        Throwable $exception,
    ): void {
        try {
            $checksum = $this->stageFile->finalize($staging, $temporaryPath, $definition->name(), $filename);
            $file = $this->record($definition->name(), $checksum, $filename, $staging);
            $run = $this->startRun->start($definition, $file);

            $this->finalizeRun->fail($definition, $file, $run, $exception);
        } catch (Throwable) {
            // The fetch failure is the one worth reporting; a bookkeeping
            // failure on top of it must not replace it.
        }
    }

    private function record(
        string $definition,
        string $checksum,
        string $filename,
        Filesystem $staging,
        mixed $pendingWatermark = null,
    ): SyncFile {
        $path = StagingArea::pathFor($definition, $checksum, $filename);

        return ($this->recordFile)($definition, $checksum, [
            'path' => $path,
            'filename' => $filename,
            'size' => $staging->size($path),
            'mtime' => $staging->lastModified($path),
            'status' => SyncFileStatus::Pending,
            'pending_watermark' => $pendingWatermark,
            'first_seen_at' => now(),
            'last_processed_at' => null,
        ]);
    }
}
