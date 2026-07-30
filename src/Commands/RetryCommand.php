<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\RestoreRunFile;
use ByRcsc\LaravelDataSync\Actions\RetryFailedRows;
use ByRcsc\LaravelDataSync\Actions\StartRun;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Jobs\TransferFileJob;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\QueueOptions;
use ByRcsc\LaravelDataSync\SyncManager;
use RuntimeException;
use Throwable;

final class RetryCommand extends Command
{
    protected $signature = 'sync:retry
        {run : The run ULID or ID to retry}
        {--failed-rows : Replay only captured failed-row payloads}';

    protected $description = 'Retry a finished data sync run';

    public function handle(
        SyncManager $manager,
        RestoreRunFile $restoreRunFile,
        RetryFailedRows $retryFailedRows,
        StartRun $startRun,
        QueueOptions $queueOptions,
    ): int {
        try {
            $reference = $this->reference($this->argument('run'));

            if ($this->option('failed-rows')) {
                $original = $this->findRun($reference);
                $definition = $manager->definition((string) $original->definition);

                if (! $definition instanceof SyncDefinition) {
                    throw new RuntimeException("Run [{$reference}] is a transfer and has no failed rows to replay. Remove [--failed-rows] to retry the whole transferred file.");
                }

                $run = $retryFailedRows($definition, $original);
                $this->components->info("Retry run [{$run->ulid}] finished with status [{$run->status->value}].");

                return self::SUCCESS;
            }

            [$file] = $restoreRunFile($reference);
            $definition = $manager->definition((string) $file->definition);
            $run = $startRun->create($definition, $file);
            $job = $definition instanceof TransferDefinition
                ? new TransferFileJob($run->id)
                : new ProcessFileJob($run->id);
            $job->onConnection($queueOptions->connection($definition));
            $job->onQueue($queueOptions->queue($definition));
            dispatch($job);
            $this->components->info("Retry run [{$run->ulid}] was dispatched.");
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function reference(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('The run reference must be a string.');
        }

        return $value;
    }

    private function findRun(string $reference): SyncRun
    {
        $query = SyncRun::query()->where('ulid', $reference);

        if (ctype_digit($reference)) {
            $query->orWhere('id', (int) $reference);
        }

        $run = $query->first();

        if (! $run instanceof SyncRun) {
            throw new RuntimeException(
                "Run [{$reference}] was not found. Its failed-row payloads may have been pruned; retry the whole archived file instead.",
            );
        }

        if (! $run->status->isTerminal()) {
            throw new RuntimeException("Run [{$reference}] has not finished and cannot be retried.");
        }

        return $run;
    }
}
