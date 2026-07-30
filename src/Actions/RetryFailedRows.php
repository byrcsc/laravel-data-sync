<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;

final readonly class RetryFailedRows
{
    public function __construct(
        private MapRow $mapRow,
        private ProcessChunkInline $processChunk,
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
                "Run [{$original->ulid}] has no captured payloads. Enable [data-sync.store_failed_payloads] and do not call withoutPayloadCapture() for this sync before the original run.",
            );
        }

        $file = $original->file()->firstOrFail();
        $run = $this->startRun->start($definition, $file);
        $writePath = $run->write_path;

        if (! $writePath instanceof WritePath) {
            throw new RuntimeException("Retry run [{$run->ulid}] has no row-sync write path.");
        }

        try {
            $rows = [];

            foreach ($failures as $failure) {
                $storedPayload = $failure->payload;

                if ($storedPayload === null) {
                    throw new RuntimeException(
                        "Run [{$original->ulid}] has a failed row without a captured payload. Enable [data-sync.store_failed_payloads] before the original run.",
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

                $mapped = null;
                $exception = null;

                try {
                    $unknown = $this->mapRow->validateSchema($definition, array_keys($payload));
                    $mapped = ($this->mapRow)($definition, $payload, $unknown);
                    $mapped = $definition->prepare($mapped);
                } catch (Throwable $mappingException) {
                    $exception = $mappingException;
                }

                $rows[] = [
                    'row_number' => $failure->row_number,
                    'raw' => $payload,
                    'mapped' => $mapped,
                    'exception' => $exception,
                    'duplicate' => false,
                ];
            }

            $rows = $this->markDuplicates($definition, $rows);
            $result = ($this->processChunk)(
                $definition,
                $run,
                $writePath,
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
     * @param  list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>  $rows
     * @return list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>
     */
    private function markDuplicates(SyncDefinition $definition, array $rows): array
    {
        $identities = [];

        foreach ($rows as $item) {
            if ($item['mapped'] === null || $item['exception'] instanceof Throwable) {
                $identities[] = null;

                continue;
            }

            $identity = [];

            foreach ($definition->matchOn() as $attribute) {
                $identity[$attribute] = $item['mapped'][$attribute] ?? null;
            }

            $identities[] = json_encode($identity, JSON_THROW_ON_ERROR);
        }

        $marked = [];

        foreach ($rows as $index => $item) {
            $identity = $identities[$index];
            $duplicates = $identity === null
                ? []
                : array_keys($identities, $identity, true);

            if ($definition->onDuplicate() === DuplicatePolicy::Fail
                && count($duplicates) > 1) {
                throw DuplicateRowException::identity(
                    $definition::class,
                    $identity,
                    $rows[$duplicates[0]]['row_number'],
                    $rows[$duplicates[1]]['row_number'],
                );
            }

            $duplicate = match ($definition->onDuplicate()) {
                DuplicatePolicy::FirstWins => count($duplicates) > 1
                    && $index !== $duplicates[0],
                DuplicatePolicy::LastWins => count($duplicates) > 1
                    && $index !== $duplicates[array_key_last($duplicates)],
                DuplicatePolicy::Fail => false,
            };
            $marked[] = [
                'row_number' => $item['row_number'],
                'raw' => $item['raw'],
                'mapped' => $item['mapped'],
                'exception' => $item['exception'],
                'duplicate' => $duplicate,
            ];
        }

        return $marked;
    }
}
