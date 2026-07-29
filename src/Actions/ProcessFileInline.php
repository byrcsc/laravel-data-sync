<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Data\SyncReport;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class ProcessFileInline
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly MapRow $mapRow,
        private readonly ProcessChunkInline $processChunk,
    ) {}

    public function __invoke(
        SyncDefinition $definition,
        SyncFile $file,
    ): SyncReport {
        $writePath = $this->writePath($definition);
        $run = SyncRun::query()->create([
            'sync_file_id' => $file->getKey(),
            'definition' => $definition->name(),
            'status' => RunStatus::Running,
            'write_path' => $writePath,
            'dry_run' => false,
            'started_at' => now(),
        ]);

        $file->forceFill(['status' => SyncFileStatus::Processing])->save();

        try {
            $report = $definition->atomic()
                ? $this->processAtomically($definition, $file, $run, $writePath)
                : $this->processInChunks($definition, $file, $run, $writePath);

            $file->forceFill([
                'status' => SyncFileStatus::Completed,
                'last_processed_at' => now(),
            ])->save();

            return $report;
        } catch (Throwable $exception) {
            $file->forceFill(['status' => SyncFileStatus::Failed])->save();
            $run->forceFill([
                'status' => RunStatus::Failed,
                'error_summary' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    private function processAtomically(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        WritePath $writePath,
    ): SyncReport {
        $modelClass = $definition->model();

        return (new $modelClass)->getConnection()->transaction(
            fn (): SyncReport => $this->processInChunks(
                $definition,
                $file,
                $run,
                $writePath,
                persistProgress: false,
            ),
        );
    }

    private function processInChunks(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        WritePath $writePath,
        bool $persistProgress = true,
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
        $selectedRows = $this->selectedRows($definition, $reader, $staging, $path, $unknown);
        $totals = new ChunkResult;
        $chunk = [];
        $chunkSize = $this->chunkSize($definition);

        foreach ($this->rows($reader, $staging, $path) as $rowNumber => $raw) {
            $mapped = null;
            $exception = null;

            try {
                $mapped = ($this->mapRow)($definition, $raw, $unknown);
                $mapped = $definition->prepare($mapped);
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
    ): ChunkResult {
        $result = ($this->processChunk)($definition, $run, $writePath, $chunk);
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

        if ($maximum !== null && $combined->failed > $maximum) {
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
    ): array {
        $selected = [];

        foreach ($this->rows($reader, $staging, $path) as $rowNumber => $raw) {
            try {
                $mapped = ($this->mapRow)($definition, $raw, $unknown);
                $mapped = $definition->prepare($mapped);
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
        $disk = config('data-sync.staging.disk', 'local');
        $name = is_string($disk) && trim($disk) !== '' ? $disk : 'local';

        return $this->filesystems->disk($name);
    }

    private function writePath(SyncDefinition $definition): WritePath
    {
        $beforeSave = new ReflectionMethod($definition, 'beforeSave');
        $afterSave = new ReflectionMethod($definition, 'afterSave');

        if ($beforeSave->getDeclaringClass()->getName() !== SyncDefinition::class) {
            return WritePath::Eloquent;
        }

        return $afterSave->getDeclaringClass()->getName() !== SyncDefinition::class
            ? WritePath::Eloquent
            : WritePath::Bulk;
    }

    private function chunkSize(SyncDefinition $definition): int
    {
        $configured = config("data-sync.overrides.{$definition->name()}.chunk_size");

        return is_int($configured) && $configured > 0
            ? $configured
            : $definition->chunkSize();
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
