<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\PruneHistory;

final class PruneCommand extends Command
{
    protected $signature = 'sync:prune';

    protected $description = 'Prune expired data sync history and stored files';

    public function handle(PruneHistory $prune): int
    {
        $report = $prune();
        $this->components->info(
            "Pruned {$report->runs} runs, {$report->rowFailures} row failures, {$report->archives} archives, and {$report->failedFiles} failed files.",
        );

        return self::SUCCESS;
    }
}
