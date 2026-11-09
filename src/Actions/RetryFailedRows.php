<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\RowIdentity;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;

final readonly class RetryFailedRows
{
    public function __construct(
        private MapRow $mapRow,
        private ProcessChunk $processChunk,
        private StartRun $startRun,
        private Dispatcher $events,
    ) {}

    public function __invoke(SyncDefinition $definition, SyncRun $original): SyncRun
    {
        $failures = $original->failures()->orderBy('row_number')->get();

        if ($failures->isEmpty()) {
            throw new RuntimeException("Run [{$original->ulid}] has no failed rows to retry.");
        }

        if ($failures->contains(fn ($failure): bool => $failure->payload === null)) {
            throw new RuntimeException(
                "Run [{$original->ulid}] has no captured payloads. Enable [data-sync.capture_failed_payloads] and do not call withoutFailedPayloadCapture() for this sync before the original run.",
            );
        }

        $file = $original->file()->firstOrFail();
        $run = $this->startRun->start($definition, $file);
        $writeStrategy = $run->write_strategy;

        if (! $writeStrategy instanceof WriteStrategy) {
            throw new RuntimeException("Retry run [{$run->ulid}] has no row-sync write strategy.");
        }

        try {
            $rows = [];

            foreach ($failures as $failure) {
                $storedPayload = $failure->payload;

                if ($storedPayload === null) {
                    throw new RuntimeException(
                        "Run [{$original->ulid}] has a failed row without a captured payload. Enable [data-sync.capture_failed_payloads] before the original run.",
                    );
                }

                $payload = [];

                foreach ($storedPayload as $key => $value) {
                    if (! is_string($value) && $value !== null) {
                        throw new RuntimeException(
                            "Run [{$original->ulid}] row [{$failure->row_number}] has a non-text captured value. Retry the whole archived file instead.",
                        );
                    }

                    $payload[$key] = $value;
                }

                try {
                    $unmappedColumns = $this->mapRow->validateHeader($definition, array_keys($payload));
                } catch (Throwable $exception) {
                    $rows[] = new ChunkRow($failure->row_number, $payload, null, $exception);

                    continue;
                }

                $rows[] = $this->mapRow->toChunkRow($definition, $failure->row_number, $payload, $unmappedColumns);
            }

            $rows = $this->markDuplicates($definition, $rows);
            $result = ($this->processChunk)(
                $definition,
                $run,
                $writeStrategy,
                $rows,
            );
            $status = $result->failed > 0
                ? RunStatus::CompletedWithErrors
                : RunStatus::Completed;
            $this->complete($run, $result, $status);
            $file->forceFill([
                'status' => SyncFileStatus::Completed,
                'last_processed_at' => now(),
            ])->save();
            $completed = $run->fresh() ?? $run;
            $this->events->dispatch(new SyncCompleted($completed));

            return $completed;
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => RunStatus::Failed,
                'error_summary' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
            $failed = $run->fresh() ?? $run;
            $this->events->dispatch(new SyncFailed($failed));

            throw $exception;
        }
    }

    private function complete(SyncRun $run, ChunkResult $result, RunStatus $status): void
    {
        $run->forceFill([
            'status' => $status,
            'rows_total' => $result->total,
            'rows_created' => $result->created,
            'rows_updated' => $result->updated,
            'rows_skipped' => $result->skipped,
            'rows_failed' => $result->failed,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The original run resolved duplicates across the whole file, but a retry
     * only sees the rows that failed, so it resolves them again among those.
     *
     * @param  list<ChunkRow>  $rows
     * @return list<ChunkRow>
     */
    private function markDuplicates(SyncDefinition $definition, array $rows): array
    {
        $identities = array_map(
            fn (ChunkRow $row): ?string => $row->mapped === null || $row->exception instanceof Throwable
                ? null
                : RowIdentity::key($definition->matchOn(), $row->mapped),
            $rows,
        );
        $marked = [];

        foreach ($rows as $index => $row) {
            $identity = $identities[$index];
            $duplicates = $identity === null
                ? []
                : array_keys($identities, $identity, true);

            if ($definition->onDuplicate() === DuplicatePolicy::Fail
                && count($duplicates) > 1) {
                throw DuplicateRowException::identity(
                    $definition::class,
                    (string) $identity,
                    $rows[$duplicates[0]]->rowNumber,
                    $rows[$duplicates[1]]->rowNumber,
                );
            }

            $duplicate = match ($definition->onDuplicate()) {
                DuplicatePolicy::FirstWins => count($duplicates) > 1
                    && $index !== $duplicates[0],
                DuplicatePolicy::LastWins => count($duplicates) > 1
                    && $index !== $duplicates[array_key_last($duplicates)],
                DuplicatePolicy::Fail => false,
            };
            $marked[] = new ChunkRow($row->rowNumber, $row->raw, $row->mapped, $row->exception, $duplicate);
        }

        return $marked;
    }
}
