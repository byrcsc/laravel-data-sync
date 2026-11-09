<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\SnapshotSyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncRun;

/**
 * A snapshot watermark moves only when a run completes, so one row that fails
 * every time holds it in place and every later run reads a growing window.
 * This names the definitions whose recent runs all ended without completing.
 */
final readonly class FindStalledWatermarks
{
    /**
     * @param  array<string, Definition>  $definitions
     * @return list<string>
     */
    public function __invoke(array $definitions): array
    {
        $runs = config('data-sync.stalled_watermark_runs', 5);
        $runs = is_int($runs) && $runs > 0 ? $runs : 5;
        $warnings = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof SnapshotSyncDefinition || ! $this->stalled($definition->name(), $runs)) {
                continue;
            }

            $name = $definition->name();
            $warnings[] = "Definition [{$name}] has not completed any of its last {$runs} runs, so its watermark has not moved and each run reads the same window again. List the failed rows with `sync:status {$name} --failures`, fix the cause, then replay them with `sync:retry`.";
        }

        return $warnings;
    }

    private function stalled(string $definition, int $runs): bool
    {
        $statuses = SyncRun::query()
            ->where('definition', $definition)
            ->where('dry_run', false)
            ->whereIn('status', [
                RunStatus::Completed,
                RunStatus::CompletedWithErrors,
                RunStatus::Failed,
                RunStatus::Cancelled,
            ])
            ->latest('id')
            ->limit($runs)
            ->pluck('status');

        return $statuses->count() === $runs
            && ! $statuses->contains(RunStatus::Completed);
    }
}
