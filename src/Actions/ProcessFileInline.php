<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Data\SyncReport;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\ChunkSize;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final class ProcessFileInline
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly MapRow $mapRow,
        private readonly ProcessChunkInline $processChunk,
        private readonly StartRun $startRun,
        private readonly FinalizeRun $finalizeRun,
    ) {}

    public function __invoke(
        SyncDefinition $definition,
        SyncFile $file,
        ?SyncRun $existingRun = null,
        bool $dryRun = false,
    ): SyncReport {
        $run = $this->startRun->start($definition, $file, $existingRun, $dryRun);
        $writePath = $run->write_path;

        if (! $writePath instanceof WritePath) {
            throw new RuntimeException("Run [{$run->ulid}] has no row-sync write path.");
        }

        try {
            $report = $definition->atomic()
                ? $this->processAtomically($definition, $file, $run, $writePath, $dryRun)
                : $this->processInChunks($definition, $file, $run, $writePath, dryRun: $dryRun);
            $completed = $this->finalizeRun->complete($definition, $file, $run);

            return new SyncReport(
                run: $completed,
                status: $completed->status,
                writePath: $writePath,
                total: $report->total,
                created: $report->created,
                updated: $report->updated,
                skipped: $report->skipped,
                failed: $report->failed,
            );
        } catch (Throwable $exception) {
            $this->finalizeRun->fail($definition, $file, $run, $exception);

            throw $exception;
        }
    }

    private function processAtomically(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        WritePath $writePath,
        bool $dryRun,
    ): SyncReport {
        $modelClass = $definition->model();

        return (new $modelClass)->getConnection()->transaction(
            fn (): SyncReport => $this->processInChunks(
                $definition,
                $file,
                $run,
                $writePath,
                persistProgress: false,
                dryRun: $dryRun,
            ),
        );
    }

    private function processInChunks(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        WritePath $writePath,
        bool $persistProgress = true,
        bool $dryRun = false,
    ): SyncReport {
        $reader = $definition->format();
        $staging = $this->stagingDisk();
        $path = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $header = $this->header($reader, $staging, $path);
        $unknown = $this->mapRow->validateSchema($definition, $header);
        $run->forceFill(['unmapped_columns' => $unknown])->save();
        $selectedRows = $this->selectedRows(
            $definition,
            $reader,
            $staging,
            $path,
            $unknown,
            $dryRun,
        );
        $totals = new ChunkResult;
        $chunk = [];
        $chunkSize = ChunkSize::for($definition);

        foreach ($this->rows($reader, $staging, $path) as $rowNumber => $raw) {
            $mapped = null;
            $exception = null;

            try {
                $mapped = ($this->mapRow)($definition, $raw, $unknown);

                if (! $dryRun) {
                    $mapped = $definition->prepare($mapped);
                }
            } catch (Throwable $mappingException) {
                $exception = $mappingException;
            }

            $chunk[] = [
                'row_number' => $rowNumber,
                'raw' => $raw,
                'mapped' => $mapped,
                'exception' => $exception,
                'duplicate' => ! isset($selectedRows[$rowNumber]),
            ];

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $totals = $this->consumeChunk(
                $definition,
                $run,
                $writePath,
                $chunk,
                $totals,
                $persistProgress,
                $dryRun,
            );
            $chunk = [];
        }

        if ($chunk !== []) {
            $totals = $this->consumeChunk(
                $definition,
                $run,
                $writePath,
                $chunk,
                $totals,
                $persistProgress,
                $dryRun,
            );
        }

        $status = $totals->failed > 0
            ? RunStatus::CompletedWithErrors
            : RunStatus::Completed;

        $this->persistRun($run, $totals, $status, true);

        return new SyncReport(
            run: $run->fresh() ?? $run,
            status: $status,
            writePath: $writePath,
            total: $totals->total,
            created: $totals->created,
            updated: $totals->updated,
            skipped: $totals->skipped,
            failed: $totals->failed,
        );
    }

    /**
     * @param  list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>  $chunk
     */
    private function consumeChunk(
        SyncDefinition $definition,
        SyncRun $run,
        WritePath $writePath,
        array $chunk,
        ChunkResult $totals,
        bool $persistProgress,
        bool $dryRun,
    ): ChunkResult {
        $result = ($this->processChunk)($definition, $run, $writePath, $chunk, $dryRun);
        $combined = new ChunkResult(
            total: $totals->total + $result->total,
            created: $totals->created + $result->created,
            updated: $totals->updated + $result->updated,
            skipped: $totals->skipped + $result->skipped,
            failed: $totals->failed + $result->failed,
        );

        if ($persistProgress) {
            $this->persistRun($run, $combined, RunStatus::Running);
        }

        $maximum = $definition->maxErrors();

        if ($maximum !== null && $combined->failed >= $maximum && $combined->failed > 0) {
            throw MaxErrorsExceededException::for($definition::class, $maximum);
        }

        return $combined;
    }

    /**
     * @param  list<string|int>  $unknown
     * @return array<int, true>
     */
    private function selectedRows(
        SyncDefinition $definition,
        Reader $reader,
        Filesystem $staging,
        string $path,
        array $unknown,
        bool $dryRun,
    ): array {
        $selected = [];

        foreach ($this->rows($reader, $staging, $path) as $rowNumber => $raw) {
            try {
                $mapped = ($this->mapRow)($definition, $raw, $unknown);

                if (! $dryRun) {
                    $mapped = $definition->prepare($mapped);
                }

                $identity = $this->identity($definition, $mapped);
            } catch (Throwable) {
                $selected["mapping-error-{$rowNumber}"] = $rowNumber;

                continue;
            }

            if (! array_key_exists($identity, $selected)) {
                $selected[$identity] = $rowNumber;

                continue;
            }

            $firstRow = $selected[$identity];

            if ($definition->onDuplicate() === DuplicatePolicy::Fail) {
                throw DuplicateRowException::identity(
                    $definition::class,
                    $identity,
                    $firstRow,
                    $rowNumber,
                );
            }

            if ($definition->onDuplicate() === DuplicatePolicy::LastWins) {
                $selected[$identity] = $rowNumber;
            }
        }

        return array_fill_keys(array_values($selected), true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function identity(SyncDefinition $definition, array $row): string
    {
        $identity = [];

        foreach ($definition->matchOn() as $attribute) {
            $identity[$attribute] = $row[$attribute] ?? null;
        }

        $encoded = json_encode($identity, JSON_THROW_ON_ERROR);

        return $encoded;
    }

    /**
     * @return list<string|int>
     */
    private function header(Reader $reader, Filesystem $staging, string $path): array
    {
        $stream = $staging->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        try {
            return $reader->header($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return Generator<int, array<string|int, string|null>>
     */
    private function rows(Reader $reader, Filesystem $staging, string $path): Generator
    {
        $stream = $staging->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        try {
            yield from $reader->rows($stream);
        } finally {
            fclose($stream);
        }
    }

    private function stagingDisk(): Filesystem
    {
        return $this->filesystems->disk(StagingArea::disk());
    }

    private function persistRun(
        SyncRun $run,
        ChunkResult $totals,
        RunStatus $status,
        bool $finished = false,
    ): void {
        $run->forceFill([
            'status' => $status,
            'rows_total' => $totals->total,
            'rows_created' => $totals->created,
            'rows_updated' => $totals->updated,
            'rows_skipped' => $totals->skipped,
            'rows_failed' => $totals->failed,
            'finished_at' => $finished ? now() : null,
        ])->save();
    }
}
