<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final readonly class RestoreRunFile
{
    public function __construct(
        private FilesystemManager $filesystems,
        private ChecksumVerifier $checksum,
    ) {}

    /**
     * @return array{SyncFile, SyncRun|null}
     */
    public function __invoke(string $reference): array
    {
        $query = SyncRun::query()->where('ulid', $reference);

        if (ctype_digit($reference)) {
            $query->orWhere('id', (int) $reference);
        }

        $run = $query->first();

        if ($run instanceof SyncRun) {
            if (! $run->status->isTerminal()) {
                throw new RuntimeException("Run [{$reference}] has not finished and cannot be retried.");
            }

            $file = $run->file()->firstOrFail();
            $path = $run->archive_path;

            if (! is_string($path) || $path === '') {
                throw new RuntimeException("Run [{$reference}] has no archived or failed-file copy to retry.");
            }

            $source = in_array($run->status, [RunStatus::Failed, RunStatus::Cancelled], true)
                ? $this->failedDisk()
                : $this->archiveDisk();
            $this->restore($source, $path, $file);

            return [$file, $run];
        }

        foreach ([$this->archiveDisk(), $this->failedDisk()] as $source) {
            foreach ($source->allFiles() as $path) {
                $match = [];

                if (preg_match('/^'.preg_quote($reference, '/').'-([a-f0-9]{64})-.+$/', basename($path), $match) !== 1) {
                    continue;
                }

                $file = SyncFile::query()->where('checksum', $match[1])->first();

                if (! $file instanceof SyncFile) {
                    continue;
                }

                $this->restore($source, $path, $file);

                return [$file, null];
            }
        }

        throw new RuntimeException("No run or archived file matching [{$reference}] was found.");
    }

    private function restore(Filesystem $source, string $sourcePath, SyncFile $file): void
    {
        if (! $source->exists($sourcePath)) {
            throw new RuntimeException("The archived file [{$sourcePath}] for this run no longer exists.");
        }

        $staging = $this->stagingDisk();
        $stagedPath = StageFile::pathFor(
            (string) $file->definition,
            (string) $file->checksum,
            (string) $file->filename,
        );
        $stream = $source->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read archived file [{$sourcePath}].");
        }

        try {
            if (! $staging->writeStream($stagedPath, $stream)) {
                throw new RuntimeException("Unable to restore archived file [{$sourcePath}] to staging.");
            }
        } finally {
            fclose($stream);
        }

        if ($this->checksum->for($staging, $stagedPath) !== $file->checksum) {
            $staging->delete($stagedPath);

            throw new RuntimeException("Checksum verification failed while restoring run file [{$sourcePath}].");
        }
    }

    private function stagingDisk(): Filesystem
    {
        return $this->filesystems->disk($this->diskName('data-sync.staging.disk', 'local'));
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

    private function diskName(string $key, string $default): string
    {
        $configured = config($key, $default);

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : $default;
    }
}
