<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\SyncManager;
use Throwable;

final class RunCommand extends Command
{
    protected $signature = 'sync:run
        {name? : The registered sync definition name}
        {--force : Process a checksum that has already completed}
        {--now : Process synchronously in this command}
        {--dry-run : Validate and report changes without writing models or touching the source}';

    protected $description = 'Discover and process registered data sync files';

    public function handle(SyncManager $manager): int
    {
        $name = $this->argument('name');
        $names = is_string($name)
            ? [$name]
            : array_keys($manager->enabled());

        if ($names === []) {
            $this->components->warn('No enabled sync definitions are registered.');

            return self::SUCCESS;
        }

        try {
            foreach ($names as $definition) {
                $this->components->info("Running [{$definition}]...");
                $dryRun = (bool) $this->option('dry-run');
                $report = $dryRun || $this->option('now')
                    ? $manager->run($definition, (bool) $this->option('force'), $dryRun)
                    : $manager->queue($definition, (bool) $this->option('force'));
                $this->render($report, $dryRun);
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function render(RunReport $report, bool $dryRun): void
    {
        if ($report->locked) {
            $this->components->warn("Skipped [{$report->definition}] because discovery is already running.");

            return;
        }

        foreach ($report->runs as $run) {
            $prefix = $dryRun ? 'Would be' : 'File run';
            $counts = $dryRun
                ? "{$run->created} would create, {$run->updated} would update, {$run->skipped} would skip, {$run->failed} would fail"
                : "{$run->created} created, {$run->updated} updated, {$run->skipped} skipped, {$run->failed} failed";

            $this->components->twoColumnDetail(
                "{$prefix} [{$run->run->ulid}] ({$run->writePath->value})",
                $counts,
            );
        }

        $this->components->info(
            "[{$report->definition}] finished: {$report->discovered} discovered, {$report->skipped} skipped, {$report->deferred} deferred.",
        );
    }
}
