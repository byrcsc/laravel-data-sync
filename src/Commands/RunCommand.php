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
        {--now : Process synchronously in this command}';

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
                $report = $this->option('now')
                    ? $manager->run($definition, (bool) $this->option('force'))
                    : $manager->queue($definition, (bool) $this->option('force'));
                $this->render($report);
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function render(RunReport $report): void
    {
        if ($report->locked) {
            $this->components->warn("Skipped [{$report->definition}] because discovery is already running.");

            return;
        }

        foreach ($report->runs as $run) {
            $this->components->twoColumnDetail(
                "File run [{$run->run->ulid}] ({$run->writePath->value})",
                "{$run->created} created, {$run->updated} updated, {$run->skipped} skipped, {$run->failed} failed",
            );
        }

        $this->components->info(
            "[{$report->definition}] finished: {$report->discovered} discovered, {$report->skipped} skipped, {$report->deferred} deferred.",
        );
    }
}
