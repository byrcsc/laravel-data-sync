<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Definitions\SnapshotSyncDefinition;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Closure;
use Generator;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

/**
 * Writes the records a fetch produces into one NDJSON snapshot in the staging
 * area, then hands that snapshot to the unchanged file pipeline through the
 * `sync_files` ledger. Every snapshot source shares these rules, whatever it
 * fetches from.
 */
final class StageSnapshot
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly FilesystemManager $filesystems,
        private readonly StageFile $stageFile,
        private readonly RecordSyncFile $recordFile,
        private readonly StartRun $startRun,
        private readonly FinalizeRun $finalizeRun,
        private readonly TrackWatermark $watermark,
    ) {}

    /**
     * @param  Closure(mixed): Generator<array-key, mixed, mixed, mixed>  $fetch  receives the stored watermark, yields each record, and returns the watermark the run commits on completion
     * @param  Closure(Throwable): Throwable  $failed  turns a fetch error into the exception the caller sees
     */
    public function __invoke(
        SnapshotSyncDefinition $definition,
        Closure $fetch,
        Closure $failed,
        bool $force = false,
    ): DiscoveryResult {
        // The same lock file discovery takes, so a scheduled fetch that overlaps
        // a running one is a recorded no-op rather than a duplicate snapshot.
        $lock = $this->cache->lock("data-sync:discover:{$definition->name()}", $definition->lockTimeout());
        $result = $lock->get(fn (): DiscoveryResult => $this->stage($definition, $fetch, $failed, $force));

        if (! $result instanceof DiscoveryResult) {
            return new DiscoveryResult([], 0, 0, true);
        }

        return $result;
    }

    /**
     * @param  Closure(mixed): Generator<array-key, mixed, mixed, mixed>  $fetch
     * @param  Closure(Throwable): Throwable  $failed
     */
    private function stage(
        SnapshotSyncDefinition $definition,
        Closure $fetch,
        Closure $failed,
        bool $force,
    ): DiscoveryResult {
        $staging = $this->filesystems->disk(StagingArea::disk());
        $filename = $definition->name().'-'.now()->format('Ymd-His').'.ndjson';
        $temporaryPath = $this->stageFile->temporaryPath($definition->name(), $filename);
        $snapshot = $this->scratchStream();

        try {
            $records = $fetch($this->watermark->current($definition->name()));

            foreach ($records as $record) {
                fwrite($snapshot, json_encode($record, JSON_THROW_ON_ERROR)."\n");
            }

            $pending = $records->getReturn();
        } catch (Throwable $exception) {
            // Whatever records did arrive are still the truthful record of what
            // the source returned, so they are preserved rather than discarded.
            $this->store($staging, $temporaryPath, $snapshot);
            $this->reportFailure($definition, $staging, $temporaryPath, $filename, $exception);

            throw $failed($exception);
        }

        $this->store($staging, $temporaryPath, $snapshot);
        $checksum = $this->stageFile->finalize($staging, $temporaryPath, $definition->name(), $filename);

        $path = StagingArea::pathFor($definition->name(), $checksum, $filename);

        // Every empty fetch has the same checksum, so the ledger would skip every
        // one after the first. An empty fetch always gets its own completed run
        // instead, so a quiet source shows up in sync:status and its watermark
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
     * @return resource
     */
    private function scratchStream(): mixed
    {
        $snapshot = fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b');

        if (! is_resource($snapshot)) {
            throw new RuntimeException('Unable to open a scratch stream for the snapshot.');
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
                throw new RuntimeException("Unable to stage the snapshot at [{$temporaryPath}].");
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
        SnapshotSyncDefinition $definition,
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
