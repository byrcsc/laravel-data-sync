<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\SourceDisk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final readonly class ArchiveFile
{
    public function __construct(
        private FilesystemManager $filesystems,
        private SourceDisk $sourceDisk,
        private ChecksumVerifier $checksum,
    ) {}

    public function __invoke(
        Definition $definition,
        SyncFile $file,
        SyncRun $run,
    ): string {
        $staging = $this->stagingDisk();
        $archive = $this->archiveDisk();
        $stagedPath = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $archivePath = $this->archivePath($definition, $file, $run);

        $this->copy($staging, $stagedPath, $archive, $archivePath);

        if ($this->checksum->for($archive, $archivePath) !== $file->checksum) {
            $archive->delete($archivePath);

            throw new RuntimeException(
                "Archive checksum verification failed for run [{$run->ulid}].",
            );
        }

        $warnings = $this->applySourceActions($definition, $file);

        if ($warnings !== []) {
            $run->forceFill(['warnings' => $warnings])->save();
        }

        return $archivePath;
    }

    public function preserveFailed(
        Definition $definition,
        SyncFile $file,
        SyncRun $run,
    ): ?string {
        $staging = $this->stagingDisk();
        $stagedPath = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );

        if (! $staging->exists($stagedPath)) {
            return null;
        }

        $failed = $this->failedDisk();
        $root = $this->root('data-sync.failed.path', 'data-sync/failed');
        $filename = $this->safeFilename((string) $file->filename);
        $failedPath = "{$root}/{$definition->name()}/{$run->ulid}-{$file->checksum}-{$filename}";

        $this->copy($staging, $stagedPath, $failed, $failedPath);

        if ($this->checksum->for($failed, $failedPath) !== $file->checksum) {
            $failed->delete($failedPath);

            throw new RuntimeException(
                "Failed-file checksum verification failed for run [{$run->ulid}].",
            );
        }

        $staging->delete($stagedPath);

        return $failedPath;
    }

    public function discardStaged(Definition $definition, SyncFile $file): void
    {
        $this->stagingDisk()->delete(StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        ));
    }

    /**
     * @return list<string>
     */
    private function applySourceActions(Definition $definition, SyncFile $file): array
    {
        $sourceDefinition = $definition->source();

        if ($sourceDefinition->sourceArchivePath() === null
            && $sourceDefinition->sourceMovePath() === null
            && ! $sourceDefinition->deletesSource()) {
            return [];
        }

        try {
            $source = $this->sourceDisk->for($sourceDefinition);
            $sourcePath = (string) $file->path;
            $filename = "{$file->checksum}-".$this->safeFilename((string) $file->filename);

            if ($sourceDefinition->sourceArchivePath() !== null) {
                $destination = $this->destination(
                    $sourceDefinition->sourceArchivePath(),
                    $filename,
                );
                $this->copy($source, $sourcePath, $source, $destination);
            }

            if ($sourceDefinition->sourceMovePath() !== null) {
                $destination = $this->destination(
                    $sourceDefinition->sourceMovePath(),
                    $filename,
                );

                if (! $source->move($sourcePath, $destination)) {
                    throw new RuntimeException("Unable to move source file [{$sourcePath}].");
                }

                return [];
            }

            if ($sourceDefinition->deletesSource() && ! $source->delete($sourcePath)) {
                throw new RuntimeException("Unable to delete source file [{$sourcePath}].");
            }
        } catch (Throwable $exception) {
            return [$exception->getMessage()];
        }

        return [];
    }

    private function copy(
        Filesystem $from,
        string $fromPath,
        Filesystem $to,
        string $toPath,
    ): void {
        $stream = $from->readStream($fromPath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read [{$fromPath}] for copying.");
        }

        try {
            if (! $to->writeStream($toPath, $stream)) {
                throw new RuntimeException("Unable to write verified copy [{$toPath}].");
            }
        } finally {
            fclose($stream);
        }
    }

    private function archivePath(
        Definition $definition,
        SyncFile $file,
        SyncRun $run,
    ): string {
        $root = $this->root('data-sync.archive.path', 'data-sync/archive');
        $date = now()->format('Y/m/d');
        $filename = $this->safeFilename((string) $file->filename);

        return "{$root}/{$definition->name()}/{$date}/{$run->ulid}-{$file->checksum}-{$filename}";
    }

    private function destination(string $directory, string $filename): string
    {
        return trim($directory, '/')."/{$filename}";
    }

    private function safeFilename(string $filename): string
    {
        return str_replace(['/', '\\'], '-', $filename);
    }

    private function root(string $key, string $default): string
    {
        $configured = config($key, $default);

        return is_string($configured) && trim($configured, '/') !== ''
            ? trim($configured, '/')
            : $default;
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
