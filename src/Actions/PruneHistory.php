<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\PruneReport;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class PruneHistory
{
    public function __construct(
        private FilesystemManager $filesystems,
    ) {}

    public function __invoke(): PruneReport
    {
        return new PruneReport(
            runs: $this->pruneRuns(),
            rowFailures: $this->pruneRowFailures(),
            archives: $this->pruneFiles(
                'data-sync.retention.archive_days',
                $this->archiveDisk(),
                $this->root('data-sync.archive.path', 'data-sync/archive'),
                'data-sync.archive.disk',
            ),
            failedFiles: $this->pruneFiles(
                'data-sync.retention.failed_files_days',
                $this->failedDisk(),
                $this->root('data-sync.failed.path', 'data-sync/failed'),
                'data-sync.failed.disk',
            ),
        );
    }

    private function pruneRuns(): int
    {
        $cutoff = $this->cutoff('data-sync.retention.runs_days');

        if ($cutoff === null) {
            return 0;
        }

        return SyncRun::query()
            ->where('status', '!=', RunStatus::Running->value)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $cutoff)
            ->toBase()
            ->delete();
    }

    private function pruneRowFailures(): int
    {
        $cutoff = $this->cutoff('data-sync.retention.row_failures_days');

        if ($cutoff === null) {
            return 0;
        }

        return SyncRowFailure::query()
            ->where('created_at', '<=', $cutoff)
            ->whereHas(
                'run',
                fn (Builder $query): Builder => $query->where('status', '!=', RunStatus::Running->value),
            )
            ->toBase()
            ->delete();
    }

    private function pruneFiles(
        string $retentionKey,
        Filesystem $disk,
        string $root,
        string $diskConfigKey,
    ): int {
        $cutoff = $this->cutoff($retentionKey);

        if ($cutoff === null) {
            return 0;
        }

        $protected = SyncRun::query()
            ->where('status', RunStatus::Running->value)
            ->whereNotNull('archive_path')
            ->pluck('archive_path')
            ->all();
        $deleted = 0;

        try {
            $paths = $disk->allFiles($root);
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                "Unable to list [{$root}] while pruning. Check [{$diskConfigKey}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        foreach ($paths as $path) {
            if (in_array($path, $protected, true)) {
                continue;
            }

            try {
                $modified = Carbon::createFromTimestamp($disk->lastModified($path));

                if ($modified->greaterThan($cutoff)) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    throw new \RuntimeException('The filesystem returned false while deleting the file.');
                }

                $deleted++;
            } catch (Throwable $exception) {
                throw new \RuntimeException(
                    "Unable to prune [{$path}]. Check [{$diskConfigKey}]: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }

        return $deleted;
    }

    private function cutoff(string $key): ?Carbon
    {
        $days = config($key);

        return is_int($days)
            ? now()->subDays($days)
            : null;
    }

    private function archiveDisk(): Filesystem
    {
        return $this->filesystems->disk($this->diskName('data-sync.archive.disk', 'local'));
    }

    private function failedDisk(): Filesystem
    {
        $configured = config('data-sync.failed.disk');

        return $this->filesystems->disk(
            is_string($configured) && trim($configured) !== ''
                ? $configured
                : $this->diskName('data-sync.staging.disk', 'local'),
        );
    }

    private function root(string $key, string $default): string
    {
        $configured = config($key, $default);

        return is_string($configured) && trim($configured, '/') !== ''
            ? trim($configured, '/')
            : $default;
    }

    private function diskName(string $key, string $default): string
    {
        $configured = config($key, $default);

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : $default;
    }
}
