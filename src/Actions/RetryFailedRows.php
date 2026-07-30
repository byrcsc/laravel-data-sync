<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
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
                /** @var array<string|int, string|null> $payload */
                $payload = $failure->payload;
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
}
