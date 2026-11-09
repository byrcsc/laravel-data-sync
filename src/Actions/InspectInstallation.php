<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DoctorReport;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\SnapshotSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\Support\SourceDisk;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use ByRcsc\LaravelDataSync\Support\TableNames;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class InspectInstallation
{
    public function __construct(
        private DatabaseManager $database,
        private ConfigurationValidator $configuration,
        private FilesystemManager $filesystems,
        private SyncManager $manager,
        private CacheManager $cache,
        private SourceDisk $sourceDisks,
    ) {}

    public function __invoke(): DoctorReport
    {
        $configurationFailures = $this->configurationFailures();
        [$definitions, $definitionFailures] = $configurationFailures === []
            ? $this->definitions()
            : [[], []];

        return new DoctorReport(
            failures: array_values(array_unique([
                ...$configurationFailures,
                ...$this->tableFailures(),
                ...$this->watermarkTableFailures(),
                ...$definitionFailures,
                ...$this->connectionFailures(),
                ...$this->definitionConnectionFailures($definitions),
                ...$this->workingDiskFailures(),
                ...$this->lockFailures(),
            ])),
            warnings: [
                ...$this->uniqueIndexWarnings($definitions),
                ...$this->stuckRunWarnings(),
                ...$this->stagingWarnings(),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function configurationFailures(): array
    {
        try {
            $this->configuration->validate();

            return [];
        } catch (Throwable $exception) {
            return [$exception->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private function tableFailures(): array
    {
        try {
            $schema = $this->database->connection()->getSchemaBuilder();
            $required = [
                TableNames::files(),
                TableNames::runs(),
                TableNames::rowFailures(),
                'job_batches',
            ];
            $missing = array_values(array_filter(
                $required,
                fn (string $table): bool => ! $schema->hasTable($table),
            ));

            return $missing === []
                ? []
                : ['Missing required database tables: ['.implode(', ', $missing).'].'];
        } catch (Throwable $exception) {
            return ["The configured database could not be inspected: {$exception->getMessage()}"];
        }
    }

    /**
     * Only a snapshot definition (API or database) needs a watermark, so a file-only installation is not
     * asked to run the migration that adds one.
     *
     * @return list<string>
     */
    private function watermarkTableFailures(): array
    {
        // Read the configured classes rather than the resolved definitions: a
        // definition that fails to resolve must not hide a missing table.
        $kind = $this->snapshotDefinitionKind();

        if ($kind === null) {
            return [];
        }

        try {
            if ($this->database->connection()->getSchemaBuilder()->hasTable(TableNames::watermarks())) {
                return [];
            }
        } catch (Throwable) {
            // tableFailures() already reports an uninspectable database.
            return [];
        }

        return ['Missing the ['.TableNames::watermarks()."] table {$kind} needs for its watermark. Publish and run the package migrations."];
    }

    private function snapshotDefinitionKind(): ?string
    {
        $configured = config('data-sync.syncs', []);

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (! is_string($class) || ! is_subclass_of($class, SnapshotSyncDefinition::class)) {
                continue;
            }

            return is_subclass_of($class, ApiSyncDefinition::class)
                ? 'an API definition'
                : 'a database definition';
        }

        return null;
    }

    /**
     * @return array{array<string, Definition>, list<string>}
     */
    private function definitions(): array
    {
        try {
            return [$this->manager->registered(), []];
        } catch (Throwable $exception) {
            return [[], ["A registered definition could not be resolved: {$exception->getMessage()}"]];
        }
    }

    /**
     * @return list<string>
     */
    private function connectionFailures(): array
    {
        $connections = config('data-sync.connections', []);

        if (! is_array($connections)) {
            return [];
        }

        $failures = [];

        foreach ($connections as $name => $connection) {
            try {
                if (! is_array($connection)) {
                    continue;
                }

                $this->filesystems->build($connection)->files();
            } catch (Throwable $exception) {
                $failures[] = "The [data-sync.connections.{$name}] connection could not be reached: {$exception->getMessage()}";
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, Definition>  $definitions
     * @return list<string>
     */
    private function definitionConnectionFailures(array $definitions): array
    {
        $failures = [];

        foreach ($definitions as $definition) {
            try {
                // For an API definition this probes its staging directory. The
                // endpoint itself is validated at boot and never called here: a
                // health check must not put load on someone else's API.
                $source = $definition->source();
                $this->sourceDisks->for($source)->files($source->pathName());
            } catch (Throwable $exception) {
                $failures[] = "The source for definition [{$definition->name()}] could not be reached: {$exception->getMessage()}";
            }

            if (! $definition instanceof TransferDefinition) {
                continue;
            }

            try {
                $destination = $definition->destination();
                $this->filesystems->disk($destination->diskName())->files();
            } catch (Throwable $exception) {
                $failures[] = "The destination for transfer [{$definition->name()}] could not be reached: {$exception->getMessage()}";
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function workingDiskFailures(): array
    {
        $failures = [];

        foreach (['staging', 'archive'] as $area) {
            $failure = $this->workingDiskFailure($area);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function workingDiskFailure(string $area): ?string
    {
        $diskName = config("data-sync.{$area}.disk");
        $directory = config("data-sync.{$area}.path", "data-sync/{$area}");
        $probe = trim(is_string($directory) ? $directory : "data-sync/{$area}", '/')
            .'/.doctor-'.Str::ulid();

        try {
            if (! is_string($diskName) || $diskName === '') {
                throw new RuntimeException('No disk is configured.');
            }

            $disk = $this->filesystems->disk($diskName);

            if (! $disk->put($probe, 'data-sync-doctor') || ! $disk->exists($probe)) {
                throw new RuntimeException('The write probe could not be stored.');
            }

            if (! $disk->delete($probe)) {
                throw new RuntimeException('The write probe could not be removed.');
            }

            return null;
        } catch (Throwable $exception) {
            if (isset($disk)) {
                try {
                    $disk->delete($probe);
                } catch (Throwable) {
                    // The original failure is more useful than cleanup failure.
                }
            }

            return "The [data-sync.{$area}.disk] disk is not writable: {$exception->getMessage()}";
        }
    }

    /**
     * @return list<string>
     */
    private function lockFailures(): array
    {
        try {
            $lock = $this->cache->lock('data-sync:doctor:'.Str::ulid(), 5);

            if (! $lock->get()) {
                throw new RuntimeException('The lock could not be acquired.');
            }

            if (! $lock->release()) {
                throw new RuntimeException('The lock could not be released.');
            }

            return [];
        } catch (Throwable $exception) {
            return ["The configured cache lock store is not working: {$exception->getMessage()}"];
        }
    }

    /**
     * @param  array<string, Definition>  $definitions
     * @return list<string>
     */
    private function uniqueIndexWarnings(array $definitions): array
    {
        $warnings = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof SyncDefinition) {
                $modelClass = $definition->model();
                $matchOn = $definition->matchOn();
            } elseif ($definition instanceof TransferDefinition) {
                $modelClass = $definition->model();
                $matchOn = $definition->matchOn();
            } else {
                continue;
            }

            if ($modelClass === null) {
                continue;
            }

            /** @var Model $model */
            $model = new $modelClass;
            $schema = $model->getConnection()->getSchemaBuilder();

            if (! $schema->hasTable($model->getTable())) {
                continue;
            }

            $sortedMatchOn = $matchOn;
            sort($sortedMatchOn);
            $hasUniqueIndex = false;

            foreach ($schema->getIndexes($model->getTable()) as $index) {
                $columns = $index['columns'];
                sort($columns);

                if (($index['unique'] || $index['primary']) && $columns === $sortedMatchOn) {
                    $hasUniqueIndex = true;

                    break;
                }
            }

            if (! $hasUniqueIndex) {
                $columns = implode(', ', $matchOn);
                $warnings[] = "Definition [{$definition->name()}] uses matchOn [{$columns}], but [{$model->getTable()}] has no matching unique index.";
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function stuckRunWarnings(): array
    {
        try {
            $schema = $this->database->connection()->getSchemaBuilder();

            if (! $schema->hasTable(TableNames::runs())) {
                return [];
            }

            $warnings = [];

            foreach (SyncRun::query()
                ->where('status', RunStatus::Running)
                ->where('started_at', '<', now()->subHour())
                ->get() as $run) {
                $warnings[] = "Run [{$run->ulid}] has been running for more than one hour.";
            }

            return $warnings;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function stagingWarnings(): array
    {
        $stagingDriver = config('filesystems.disks.'.StagingArea::disk().'.driver');

        return $stagingDriver === 'local'
            ? ['The staging disk uses local storage. Multi-server workers require a shared staging disk.']
            : [];
    }
}
