<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\PruneHistory;
use Throwable;

final class PruneCommand extends Command
{
    protected $signature = 'sync:prune';

    protected $description = 'Prune expired data sync history and stored files';

    public function handle(PruneHistory $prune): int
    {
        try {
            $report = $prune();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Pruned {$report->runs} runs, {$report->rowFailures} row failures, {$report->archives} archives, and {$report->failedFiles} failed files.",
        );

        return self::SUCCESS;
    }
}
