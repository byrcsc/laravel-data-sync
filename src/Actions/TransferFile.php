<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Data\SyncReport;
use ByRcsc\LaravelDataSync\Data\TransferredFile;
use ByRcsc\LaravelDataSync\Definitions\FilenamePattern;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Enums\ExistingFilePolicy;
use ByRcsc\LaravelDataSync\Enums\UnmatchedFilenamePolicy;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\PathTemplate;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final readonly class TransferFile
{
    public function __construct(
        private FilesystemManager $filesystems,
        private ChecksumVerifier $checksum,
        private PathTemplate $pathTemplate,
        private LinkTransferredFile $linkFile,
        private StartRun $startRun,
        private FinalizeRun $finalizeRun,
    ) {}

    public function __invoke(
        TransferDefinition $definition,
        SyncFile $file,
        ?SyncRun $existingRun = null,
        bool $dryRun = false,
    ): SyncReport {
        $run = $this->startRun->start($definition, $file, $existingRun, $dryRun);

        try {
            $segments = (new FilenamePattern($definition->matchFilename()))
                ->match((string) $file->filename);

            if ($segments === null) {
                $reason = "Filename [{$file->filename}] does not match [{$definition->matchFilename()}].";

                if ($definition->onUnmatched() === UnmatchedFilenamePolicy::Fail) {
                    throw new RuntimeException($reason);
                }

                if ($dryRun) {
                    $run->forceFill([
                        'rows_total' => 1,
                        'rows_skipped' => 1,
                        'error_summary' => $reason,
                    ])->save();
                    $completed = $this->finalizeRun->complete($definition, $file, $run);
                } else {
                    $completed = $this->finalizeRun->skip($definition, $file, $run, $reason);
                }

                return $this->report($completed);
            }

            $destination = $definition->destination();
            $disk = $this->filesystems->disk($destination->diskName());
            $values = [
                ...$segments,
                'filename' => (string) $file->filename,
                'source_path' => (string) $file->path,
                'checksum' => (string) $file->checksum,
                'size' => $file->size,
                'mtime' => $file->mtime,
                'extension' => pathinfo((string) $file->filename, PATHINFO_EXTENSION),
            ];
            $path = $this->pathTemplate->render($destination->pathTemplate(), $values);
            $values['path'] = $path;
            $skipped = false;

            if ($disk->exists($path)) {
                $existingChecksum = $this->checksum->for($disk, $path);

                if ($existingChecksum === $file->checksum) {
                    $skipped = true;
                } elseif ($destination->existingPolicy() === ExistingFilePolicy::Fail) {
                    throw new RuntimeException("Destination file [{$path}] already exists with a different checksum.");
                }
            }

            if (! $skipped && ! $dryRun) {
                $this->copyToDestination($definition, $file, $disk, $path);
            }

            if (! $dryRun) {
                ($this->linkFile)($definition, $values);
                $transferred = new TransferredFile(
                    run: $run,
                    file: $file,
                    destinationDisk: $destination->diskName(),
                    destinationPath: $path,
                    checksum: (string) $file->checksum,
                    segments: $segments,
                    skipped: $skipped,
                );
                $definition->afterTransfer($transferred);
            }
            $run->forceFill([
                'rows_total' => 1,
                'rows_created' => $skipped ? 0 : 1,
                'rows_skipped' => $skipped ? 1 : 0,
            ])->save();
            $completed = $this->finalizeRun->complete($definition, $file, $run);

            return $this->report($completed);
        } catch (Throwable $exception) {
            $this->finalizeRun->fail($definition, $file, $run, $exception);

            throw $exception;
        }
    }

    private function copyToDestination(
        TransferDefinition $definition,
        SyncFile $file,
        Filesystem $destination,
        string $destinationPath,
    ): void {
        $staging = $this->stagingDisk();
        $stagedPath = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $stream = $staging->readStream($stagedPath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged transfer file [{$stagedPath}].");
        }

        try {
            if (! $destination->writeStream($destinationPath, $stream)) {
                throw new RuntimeException("Unable to write destination file [{$destinationPath}].");
            }
        } finally {
            fclose($stream);
        }

        if ($this->checksum->for($destination, $destinationPath) !== $file->checksum) {
            $destination->delete($destinationPath);

            throw new RuntimeException("Destination checksum verification failed for [{$destinationPath}].");
        }
    }

    private function report(SyncRun $run): SyncReport
    {
        return new SyncReport(
            run: $run,
            status: $run->status,
            writePath: null,
            total: $run->rows_total,
            created: $run->rows_created,
            updated: $run->rows_updated,
            skipped: $run->rows_skipped,
            failed: $run->rows_failed,
        );
    }

    private function stagingDisk(): Filesystem
    {
        return $this->filesystems->disk(StagingArea::disk());
    }
}
