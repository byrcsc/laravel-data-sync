<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\ArchiveArea;
use ByRcsc\LaravelDataSync\Support\FailedArea;
use ByRcsc\LaravelDataSync\Support\Filename;
use ByRcsc\LaravelDataSync\Support\SourceDisk;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final readonly class ArchiveFile
{
    public function __construct(
        private FilesystemManager $filesystems,
        private SourceDisk $sourceDisks,
        private ChecksumVerifier $checksum,
    ) {}

    public function __invoke(
        Definition $definition,
        SyncFile $file,
        SyncRun $run,
    ): string {
        $staging = $this->stagingDisk();
        $archive = $this->archiveDisk();
        $stagedPath = StagingArea::pathFor(
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
        $stagedPath = StagingArea::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );

        if (! $staging->exists($stagedPath)) {
            return null;
        }

        $failed = $this->failedDisk();
        $root = FailedArea::root();
        $filename = Filename::safe((string) $file->filename);
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
        $this->stagingDisk()->delete(StagingArea::pathFor(
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
        $source = $definition->source();

        if ($source->copyPath() === null
            && $source->movePath() === null
            && ! $source->deletesOriginal()) {
            return [];
        }

        try {
            $sourceDisk = $this->sourceDisks->for($source);
            $sourcePath = (string) $file->path;
            $filename = "{$file->checksum}-".Filename::safe((string) $file->filename);

            if ($source->copyPath() !== null) {
                $destination = $this->destination(
                    $source->copyPath(),
                    $filename,
                );
                $this->copy($sourceDisk, $sourcePath, $sourceDisk, $destination);
            }

            if ($source->movePath() !== null) {
                $destination = $this->destination(
                    $source->movePath(),
                    $filename,
                );

                if (! $sourceDisk->move($sourcePath, $destination)) {
                    throw new RuntimeException("Unable to move source file [{$sourcePath}].");
                }

                return [];
            }

            if ($source->deletesOriginal() && ! $sourceDisk->delete($sourcePath)) {
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
        $root = ArchiveArea::root();
        $date = now()->format('Y/m/d');
        $filename = Filename::safe((string) $file->filename);

        return "{$root}/{$definition->name()}/{$date}/{$run->ulid}-{$file->checksum}-{$filename}";
    }

    private function destination(string $directory, string $filename): string
    {
        return trim($directory, '/')."/{$filename}";
    }

    private function stagingDisk(): Filesystem
    {
        return $this->filesystems->disk(StagingArea::disk());
    }

    private function archiveDisk(): Filesystem
    {
        return $this->filesystems->disk(ArchiveArea::disk());
    }

    private function failedDisk(): Filesystem
    {
        return $this->filesystems->disk(FailedArea::disk());
    }
}
