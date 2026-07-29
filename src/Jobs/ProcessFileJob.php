<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Jobs;

use ByRcsc\LaravelDataSync\Actions\FinalizeRun;
use ByRcsc\LaravelDataSync\Actions\PlanFile;
use ByRcsc\LaravelDataSync\Actions\ProcessFileInline;
use ByRcsc\LaravelDataSync\Actions\StartRun;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\QueueOptions;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class ProcessFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function handle(
        SyncManager $manager,
        StartRun $startRun,
        PlanFile $planFile,
        ProcessFileInline $processFile,
        FinalizeRun $finalizeRun,
        QueueOptions $queueOptions,
    ): void {
        $run = SyncRun::query()->findOrFail($this->runId);
        $file = $run->file()->firstOrFail();
        $definition = $manager->definition((string) $run->definition);

        if ($definition->atomic()) {
            $processFile($definition, $file, $run);

            return;
        }

        $startRun->start($definition, $file, $run);

        try {
            $plan = $planFile($definition, $file);
            $run->forceFill(['unmapped_columns' => $plan->unmappedColumns])->save();

            if ($plan->chunks === []) {
                $finalizeRun->complete($definition, $file, $run);

                return;
            }

            $jobs = [];

            foreach ($plan->chunks as $chunk) {
                $job = new ProcessChunkJob(
                    runId: $run->id,
                    unmappedColumns: $plan->unmappedColumns,
                    selectedRows: $plan->selectedRows,
                    offset: $chunk['offset'],
                    limit: $chunk['limit'],
                );
                $job->onConnection($queueOptions->connection($definition));
                $job->onQueue($queueOptions->queue($definition));
                $jobs[] = $job;
            }

            $runId = $run->id;
            $pending = Bus::batch($jobs)
                ->name("data-sync:{$definition->name()}:{$run->ulid}")
                ->before(static function (Batch $batch) use ($runId): void {
                    SyncRun::query()
                        ->whereKey($runId)
                        ->update(['batch_id' => $batch->id]);
                })
                ->then(static function (Batch $batch) use ($runId): void {
                    $freshRun = SyncRun::query()->whereKey($runId)->firstOrFail();
                    $freshFile = $freshRun->file()->firstOrFail();
                    $freshDefinition = app(SyncManager::class)
                        ->definition((string) $freshRun->definition);

                    app(FinalizeRun::class)->complete($freshDefinition, $freshFile, $freshRun);
                })
                ->catch(static function (Batch $batch, Throwable $exception) use ($runId): void {
                    $freshRun = SyncRun::query()->whereKey($runId)->firstOrFail();
                    $freshFile = $freshRun->file()->firstOrFail();
                    $freshDefinition = app(SyncManager::class)
                        ->definition((string) $freshRun->definition);

                    app(FinalizeRun::class)->fail(
                        $freshDefinition,
                        $freshFile,
                        $freshRun,
                        $exception,
                    );
                })
                ->finally(static function (Batch $batch) use ($runId): void {
                    if (! $batch->cancelled()) {
                        return;
                    }

                    $freshRun = SyncRun::query()->whereKey($runId)->firstOrFail();

                    if ($freshRun->status->isTerminal()) {
                        return;
                    }

                    $freshFile = $freshRun->file()->firstOrFail();
                    $freshDefinition = app(SyncManager::class)
                        ->definition((string) $freshRun->definition);

                    app(FinalizeRun::class)->cancel($freshDefinition, $freshFile, $freshRun);
                });

            $connection = $queueOptions->connection($definition);
            $queue = $queueOptions->queue($definition);

            if ($connection !== null) {
                $pending->onConnection($connection);
            }

            if ($queue !== null) {
                $pending->onQueue($queue);
            }

            $pending->dispatch();
        } catch (Throwable $exception) {
            $run->refresh();

            if ($run->status->isTerminal()) {
                throw $exception;
            }

            $finalizeRun->fail($definition, $file, $run, $exception);

            throw $exception;
        }
    }
}
