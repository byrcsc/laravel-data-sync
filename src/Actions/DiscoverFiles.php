<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Support\SourceDisk;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Throwable;

final class DiscoverFiles
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly FilesystemManager $filesystems,
        private readonly SourceDisk $sourceDisk,
        private readonly StageFile $stageFile,
    ) {}

    public function __invoke(Definition $definition, bool $force = false): DiscoveryResult
    {
        $lock = $this->cache->lock("data-sync:discover:{$definition->name()}", 300);
        $result = $lock->get(fn (): DiscoveryResult => $this->discover($definition, $force));

        if (! $result instanceof DiscoveryResult) {
            return new DiscoveryResult([], 0, 0, true);
        }

        return $result;
    }

    private function discover(Definition $definition, bool $force): DiscoveryResult
    {
        $sourceDefinition = $definition->source();
        $source = $this->sourceDisk->for($sourceDefinition);
        $staging = $this->stagingDisk();
        $files = [];
        $skipped = 0;
        $deferred = 0;

        foreach ($this->candidatePaths($source, $sourceDefinition->directory()) as $path) {
            $size = $source->size($path);
            $mtime = $source->lastModified($path);

            if ($this->isTooYoung($mtime, $sourceDefinition->minimumAgeSeconds())) {
                $deferred++;

                continue;
            }

            if (! $force && $this->matchesSuccessfulStat($definition->name(), $path, $size, $mtime)) {
                $skipped++;

                continue;
            }

            $filename = basename($path);
            $checksum = ($this->stageFile)(
                $source,
                $staging,
                $path,
                $definition->name(),
                $filename,
            );

            $completed = SyncFile::query()
                ->where('definition', $definition->name())
                ->where('checksum', $checksum)
                ->where('status', SyncFileStatus::Completed->value)
                ->first();

            if (! $force && $completed instanceof SyncFile) {
                $staging->delete(StageFile::pathFor($definition->name(), $checksum, $filename));
                $skipped++;

                continue;
            }

            $files[] = $this->record(
                $definition->name(),
                $checksum,
                $path,
                $filename,
                $size,
                $mtime,
            );
        }

        return new DiscoveryResult($files, $skipped, $deferred);
    }

    private function stagingDisk(): Filesystem
    {
        return $this->filesystems->disk(StagingArea::disk());
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(Filesystem $source, string $path): array
    {
        try {
            $files = $source->allFiles($path);

            if ($files !== []) {
                return array_values($files);
            }
        } catch (Throwable) {
        }

        if ($path !== '' && $source->exists($path)) {
            return [$path];
        }

        return [];
    }

    private function isTooYoung(int $mtime, int $minimumAgeSeconds): bool
    {
        if ($minimumAgeSeconds === 0) {
            return false;
        }

        return Carbon::createFromTimestamp($mtime)->addSeconds($minimumAgeSeconds)->isFuture();
    }

    private function matchesSuccessfulStat(
        string $definition,
        string $path,
        int $size,
        int $mtime,
    ): bool {
        return SyncFile::query()
            ->where('definition', $definition)
            ->where('path', $path)
            ->where('size', $size)
            ->where('mtime', $mtime)
            ->where('status', SyncFileStatus::Completed->value)
            ->exists();
    }

    private function record(
        string $definition,
        string $checksum,
        string $path,
        string $filename,
        int $size,
        int $mtime,
    ): SyncFile {
        $attributes = [
            'path' => $path,
            'filename' => $filename,
            'size' => $size,
            'mtime' => $mtime,
            'status' => SyncFileStatus::Pending,
            'first_seen_at' => now(),
            'last_processed_at' => null,
        ];

        $file = SyncFile::query()
            ->where('definition', $definition)
            ->where('checksum', $checksum)
            ->first();

        if ($file instanceof SyncFile) {
            $file->forceFill($attributes)->save();

            return $file;
        }

        try {
            return SyncFile::query()->create([
                'definition' => $definition,
                'checksum' => $checksum,
                ...$attributes,
            ]);
        } catch (QueryException) {
            /** @var SyncFile $existing */
            $existing = SyncFile::query()
                ->where('definition', $definition)
                ->where('checksum', $checksum)
                ->firstOrFail();

            return $existing;
        }
    }
}
