<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Contracts\DefinitionProvider;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Exceptions\InvalidConfigurationException;
use Cron\CronExpression;
use Illuminate\Contracts\Container\Container;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;

final class ConfigurationValidator
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * The checks that are safe while the application boots. A definition
     * provider is only checked for its type here, because it may read tables
     * that do not exist yet, such as during `migrate`.
     */
    public function validate(): void
    {
        $config = $this->config();
        [$definitions, $providers] = $this->definitions(Payload::array($config, 'syncs'));

        $this->validateOverrides(Payload::object($config, 'overrides') ?? [], $providers === [] ? $definitions : null);
        $this->validateRetention(Payload::object($config, 'retention') ?? []);
        $this->validateConnections(Payload::object($config, 'connections') ?? []);
        $this->validateStalledWatermarkRuns($config['stalled_watermark_runs'] ?? 5);
    }

    /**
     * Every registered definition, including the ones each provider returns,
     * keyed by name.
     *
     * @return array<string, Definition>
     */
    public function resolve(): array
    {
        $this->validate();
        $config = $this->config();
        [$definitions, $providers] = $this->definitions(Payload::array($config, 'syncs'));

        foreach ($providers as $provider) {
            foreach ($provider->definitions() as $definition) {
                if (! $definition instanceof SyncDefinition && ! $definition instanceof TransferDefinition) {
                    throw InvalidConfigurationException::providerReturnedWrongType($provider::class, get_debug_type($definition));
                }

                $definitions = $this->add($definitions, $definition, fromProvider: true);
            }
        }

        if ($providers !== []) {
            $this->validateOverrides(Payload::object($config, 'overrides') ?? [], $definitions);
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = Payload::asObject(config('data-sync')) ?? [];
        $this->rejectRenamedKeys($config);

        return $config;
    }

    private function validateStalledWatermarkRuns(mixed $runs): void
    {
        if (! is_int($runs) || $runs < 1) {
            throw InvalidConfigurationException::invalidStalledWatermarkRuns();
        }
    }

    /**
     * A published config keeps an old key after an upgrade. Ignoring it would
     * fall back to the default, so a host that turned payload capture off would
     * quietly start storing row payloads again.
     *
     * @param  array<string, mixed>  $config
     */
    private function rejectRenamedKeys(array $config): void
    {
        $renamed = ['store_failed_payloads' => 'capture_failed_payloads'];

        foreach ($renamed as $old => $new) {
            if (array_key_exists($old, $config)) {
                throw InvalidConfigurationException::renamedKey($old, $new);
            }
        }
    }

    /**
     * The definitions listed by class, and the providers, which are not called.
     *
     * @param  array<array-key, mixed>  $configured
     * @return array{array<string, Definition>, list<DefinitionProvider>}
     */
    private function definitions(array $configured): array
    {
        $definitions = [];
        $providers = [];

        foreach (array_values($configured) as $index => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw InvalidConfigurationException::unknownDefinition($index, $class);
            }

            if (is_subclass_of($class, DefinitionProvider::class)) {
                $provider = $this->container->make($class);

                if ($provider instanceof DefinitionProvider) {
                    $providers[] = $provider;
                }

                continue;
            }

            if (! is_subclass_of($class, SyncDefinition::class)
                && ! is_subclass_of($class, TransferDefinition::class)) {
                throw InvalidConfigurationException::definitionHasWrongType($index, $class);
            }

            /** @var Definition $definition */
            $definition = $this->container->make($class);
            $definitions = $this->add($definitions, $definition);
        }

        return [$definitions, $providers];
    }

    /**
     * A name keys config overrides, staging directories, and Artisan
     * arguments. A provider builds names from data, such as an account's
     * label, so its names are limited to characters that are safe in all
     * three. A dot would split a `data-sync.overrides` key. Names of
     * definitions listed by class keep the 2.0 rules.
     *
     * @param  array<string, Definition>  $definitions
     * @return array<string, Definition>
     */
    private function add(array $definitions, Definition $definition, bool $fromProvider = false): array
    {
        $name = $definition->name();

        if ($fromProvider && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw InvalidConfigurationException::invalidDefinitionName($name, $definition::class);
        }

        if (array_key_exists($name, $definitions)) {
            throw InvalidConfigurationException::duplicateDefinitionName($name);
        }

        $definitions[$name] = $definition;

        return $definitions;
    }

    /**
     * Names are only known once providers run, so with a provider configured
     * the boot check passes null and an unknown name is caught by resolve().
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, Definition>|null  $definitions
     */
    private function validateOverrides(array $overrides, ?array $definitions): void
    {
        foreach ($overrides as $name => $settings) {
            if ($definitions !== null && ! array_key_exists($name, $definitions)) {
                throw InvalidConfigurationException::unknownOverride($name);
            }

            if (! is_array($settings)) {
                continue;
            }

            foreach (array_keys($settings) as $key) {
                if (! in_array($key, ['enabled', 'schedule', 'queue', 'chunk_size'], true)) {
                    throw InvalidConfigurationException::nonOperationalOverride($name, (string) $key);
                }
            }

            if (array_key_exists('chunk_size', $settings)) {
                $chunkSize = $settings['chunk_size'];

                if (! is_int($chunkSize) || $chunkSize < 1) {
                    throw InvalidConfigurationException::invalidChunkSize($name);
                }
            }

            if (array_key_exists('schedule', $settings)) {
                $schedule = $settings['schedule'];

                if ($schedule === false) {
                    continue;
                }

                if (! is_string($schedule) || ! CronExpression::isValidExpression($schedule)) {
                    throw InvalidConfigurationException::invalidSchedule($name);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $retention
     */
    private function validateRetention(array $retention): void
    {
        foreach ($retention as $key => $days) {
            if ($days === null) {
                continue;
            }

            if (is_int($days) && $days >= 0) {
                continue;
            }

            throw InvalidConfigurationException::invalidRetention($key);
        }
    }

    /**
     * @param  array<string, mixed>  $connections
     */
    private function validateConnections(array $connections): void
    {
        foreach ($connections as $name => $connection) {
            if (! is_array($connection)) {
                throw InvalidConfigurationException::invalidConnection($name);
            }

            $driver = $connection['driver'] ?? null;

            if (! is_string($driver) || trim($driver) === '') {
                throw InvalidConfigurationException::invalidConnection($name);
            }

            if ($driver === 'ftp' && ! class_exists(FtpAdapter::class)) {
                throw InvalidConfigurationException::missingAdapter(
                    $name,
                    $driver,
                    'league/flysystem-ftp',
                );
            }

            if ($driver === 'sftp' && ! class_exists(SftpAdapter::class)) {
                throw InvalidConfigurationException::missingAdapter(
                    $name,
                    $driver,
                    'league/flysystem-sftp-v3',
                );
            }
        }
    }
}
