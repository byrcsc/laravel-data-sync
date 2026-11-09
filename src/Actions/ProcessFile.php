<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Data\RunResult;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\ChunkSize;
use RuntimeException;
use Throwable;

/**
 * Runs a row sync over one staged payload in the current process. The queued
 * path splits the same work across ProcessFileJob and ProcessChunkJob.
 */
final readonly class ProcessFile
{
    public function __construct(
        private PlanFile $planFile,
        private ReadStagedFile $readStagedFile,
        private MapRow $mapRow,
        private ProcessChunk $processChunk,
        private StartRun $startRun,
        private FinalizeRun $finalizeRun,
    ) {}

    public function __invoke(
        SyncDefinition $definition,
        SyncFile $file,
        ?SyncRun $existingRun = null,
        bool $dryRun = false,
    ): RunResult {
        $run = $this->startRun->start($definition, $file, $existingRun, $dryRun);
        $writeStrategy = $run->write_strategy;

        if (! $writeStrategy instanceof WriteStrategy) {
            throw new RuntimeException("Run [{$run->ulid}] has no row-sync write strategy.");
        }

        try {
            $totals = $definition->atomic()
                ? $this->processAtomically($definition, $file, $run, $writeStrategy, $dryRun)
                : $this->processInChunks($definition, $file, $run, $writeStrategy, dryRun: $dryRun);
            $completed = $this->finalizeRun->complete($definition, $file, $run);

            return new RunResult(
                run: $completed,
                status: $completed->status,
                writeStrategy: $writeStrategy,
                total: $totals->total,
                created: $totals->created,
                updated: $totals->updated,
                skipped: $totals->skipped,
                failed: $totals->failed,
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
        WriteStrategy $writeStrategy,
        bool $dryRun,
    ): ChunkResult {
        $modelClass = $definition->model();

        return (new $modelClass)->getConnection()->transaction(
            fn (): ChunkResult => $this->processInChunks(
                $definition,
                $file,
                $run,
                $writeStrategy,
                saveProgress: false,
                dryRun: $dryRun,
            ),
        );
    }

    private function processInChunks(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        WriteStrategy $writeStrategy,
        bool $saveProgress = true,
        bool $dryRun = false,
    ): ChunkResult {
        $plan = ($this->planFile)($definition, $file, prepare: ! $dryRun);
        $run->forceFill(['unmapped_columns' => $plan->unmappedColumns])->save();
        $totals = new ChunkResult;
        $chunk = [];
        $chunkSize = ChunkSize::for($definition);

        foreach ($this->readStagedFile->rows($definition, $file) as $rowNumber => $raw) {
            $chunk[] = $this->mapRow->toChunkRow(
                $definition,
                $rowNumber,
                $raw,
                $plan->unmappedColumns,
                duplicate: ! isset($plan->keptRows[$rowNumber]),
                prepare: ! $dryRun,
            );

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $totals = $this->consumeChunk($definition, $run, $writeStrategy, $chunk, $totals, $saveProgress, $dryRun);
            $chunk = [];
        }

        if ($chunk !== []) {
            $totals = $this->consumeChunk($definition, $run, $writeStrategy, $chunk, $totals, $saveProgress, $dryRun);
        }

        $status = $totals->failed > 0
            ? RunStatus::CompletedWithErrors
            : RunStatus::Completed;

        $this->saveTotals($run, $totals, $status, finished: true);

        return $totals;
    }

    /**
     * @param  list<ChunkRow>  $chunk
     */
    private function consumeChunk(
        SyncDefinition $definition,
        SyncRun $run,
        WriteStrategy $writeStrategy,
        array $chunk,
        ChunkResult $totals,
        bool $saveProgress,
        bool $dryRun,
    ): ChunkResult {
        $result = ($this->processChunk)($definition, $run, $writeStrategy, $chunk, $dryRun);
        $combined = new ChunkResult(
            total: $totals->total + $result->total,
            created: $totals->created + $result->created,
            updated: $totals->updated + $result->updated,
            skipped: $totals->skipped + $result->skipped,
            failed: $totals->failed + $result->failed,
        );

        if ($saveProgress) {
            $this->saveTotals($run, $combined, RunStatus::Running);
        }

        $maximum = $definition->maxErrors();

        if ($maximum !== null && $combined->failed >= $maximum && $combined->failed > 0) {
            throw MaxErrorsExceededException::for($definition::class, $maximum);
        }

        return $combined;
    }

    private function saveTotals(
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
