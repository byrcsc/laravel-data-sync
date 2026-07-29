<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync;

use ByRcsc\LaravelDataSync\Actions\DiscoverFiles;
use ByRcsc\LaravelDataSync\Actions\ProcessFileInline;
use ByRcsc\LaravelDataSync\Actions\StartRun;
use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\Data\SyncReport;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\Support\QueueOptions;
use Illuminate\Contracts\Container\Container;

final class SyncManager
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigurationValidator $configuration,
        private readonly DefinitionValidator $definitions,
        private readonly DiscoverFiles $discoverFiles,
        private readonly ProcessFileInline $processFile,
        private readonly StartRun $startRun,
        private readonly QueueOptions $queueOptions,
    ) {}

    /**
     * @return array<string, SyncDefinition>
     */
    public function registered(): array
    {
        $this->configuration->validate();
        $configured = config('data-sync.syncs', []);
        $classes = is_array($configured) ? $configured : [];
        $definitions = [];

        foreach ($classes as $class) {
            if (! is_string($class)) {
                continue;
            }

            /** @var SyncDefinition $definition */
            $definition = $this->container->make($class);
            $this->definitions->validate($definition);
            $definitions[$definition->name()] = $definition;
        }

        return $definitions;
    }

    public function definition(string $name): SyncDefinition
    {
        $definition = $this->registered()[$name] ?? null;

        if (! $definition instanceof SyncDefinition) {
            throw InvalidDefinitionException::unknownName($name);
        }

        return $definition;
    }

    /**
     * @return array<string, SyncDefinition>
     */
    public function enabled(): array
    {
        return array_filter(
            $this->registered(),
            function (SyncDefinition $definition): bool {
                $override = config("data-sync.overrides.{$definition->name()}.enabled", true);

                return $override !== false;
            },
        );
    }

    public function run(string $name, bool $force = false): RunReport
    {
        $definition = $this->definition($name);
        $discovery = ($this->discoverFiles)($definition, $force);
        $runs = [];

        foreach ($discovery->files as $file) {
            $runs[] = ($this->processFile)($definition, $file);
        }

        return new RunReport(
            definition: $name,
            runs: $runs,
            discovered: count($discovery->files),
            skipped: $discovery->skipped,
            deferred: $discovery->deferred,
            locked: $discovery->locked,
        );
    }

    public function queue(string $name, bool $force = false): RunReport
    {
        $definition = $this->definition($name);
        $discovery = ($this->discoverFiles)($definition, $force);
        $runs = [];

        foreach ($discovery->files as $file) {
            $run = $this->startRun->create($definition, $file);
            $job = new ProcessFileJob($run->id);
            $job->onConnection($this->queueOptions->connection($definition));
            $job->onQueue($this->queueOptions->queue($definition));
            dispatch($job);
            $current = $run->fresh() ?? $run;
            $runs[] = new SyncReport(
                run: $current,
                status: $current->status,
                writePath: $current->write_path,
                total: $current->rows_total,
                created: $current->rows_created,
                updated: $current->rows_updated,
                skipped: $current->rows_skipped,
                failed: $current->rows_failed,
            );
        }

        return new RunReport(
            definition: $name,
            runs: $runs,
            discovered: count($discovery->files),
            skipped: $discovery->skipped,
            deferred: $discovery->deferred,
            locked: $discovery->locked,
        );
    }
}
