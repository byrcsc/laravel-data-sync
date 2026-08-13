<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync;

use ByRcsc\LaravelDataSync\Actions\DiscoverFiles;
use ByRcsc\LaravelDataSync\Actions\FetchApiPayload;
use ByRcsc\LaravelDataSync\Actions\ProcessFileInline;
use ByRcsc\LaravelDataSync\Actions\StartRun;
use ByRcsc\LaravelDataSync\Actions\TransferFile;
use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\Data\SyncReport;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinitionValidator;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Jobs\TransferFileJob;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\Support\QueueOptions;
use Illuminate\Contracts\Container\Container;

final class SyncManager
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigurationValidator $configuration,
        private readonly DefinitionValidator $definitions,
        private readonly TransferDefinitionValidator $transferDefinitions,
        private readonly DiscoverFiles $discoverFiles,
        private readonly FetchApiPayload $fetchApiPayload,
        private readonly ProcessFileInline $processFile,
        private readonly TransferFile $transferFile,
        private readonly StartRun $startRun,
        private readonly QueueOptions $queueOptions,
    ) {}

    /**
     * @return array<string, Definition>
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

            /** @var Definition $definition */
            $definition = $this->container->make($class);

            if ($definition instanceof SyncDefinition) {
                $this->definitions->validate($definition);
            } elseif ($definition instanceof TransferDefinition) {
                $this->transferDefinitions->validate($definition);
            }

            $definitions[$definition->name()] = $definition;
        }

        return $definitions;
    }

    public function definition(string $name): Definition
    {
        $definition = $this->registered()[$name] ?? null;

        if (! $definition instanceof Definition) {
            throw InvalidDefinitionException::unknownName($name);
        }

        return $definition;
    }

    /**
     * @return array<string, Definition>
     */
    public function enabled(): array
    {
        return array_filter(
            $this->registered(),
            function (Definition $definition): bool {
                $override = config("data-sync.overrides.{$definition->name()}.enabled", true);

                return $override !== false;
            },
        );
    }

    public function run(
        string $name,
        bool $force = false,
        bool $dryRun = false,
    ): RunReport {
        $definition = $this->definition($name);
        $discovery = $this->collect($definition, $force);
        $runs = [];

        foreach ($discovery->files as $file) {
            if ($definition instanceof TransferDefinition) {
                $runs[] = ($this->transferFile)($definition, $file, dryRun: $dryRun);

                continue;
            }

            if ($definition instanceof SyncDefinition) {
                $runs[] = ($this->processFile)(
                    $definition,
                    $file,
                    dryRun: $dryRun,
                );

                continue;
            }

            throw InvalidDefinitionException::unknownName($name);
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
        $discovery = $this->collect($definition, $force);
        $runs = [];

        foreach ($discovery->files as $file) {
            $run = $this->startRun->create($definition, $file);
            $job = $definition instanceof TransferDefinition
                ? new TransferFileJob($run->id)
                : new ProcessFileJob($run->id);
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

    /**
     * An API definition produces its own payload by fetching it; every other
     * definition finds one already sitting on a disk.
     */
    private function collect(Definition $definition, bool $force): DiscoveryResult
    {
        return $definition instanceof ApiSyncDefinition
            ? ($this->fetchApiPayload)($definition, $force)
            : ($this->discoverFiles)($definition, $force);
    }
}
