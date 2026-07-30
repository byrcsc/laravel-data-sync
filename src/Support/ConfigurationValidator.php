<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

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

    public function validate(): void
    {
        /** @var array<string, mixed> $config */
        $config = Payload::asObject(config('data-sync')) ?? [];
        $definitions = $this->definitions(Payload::array($config, 'syncs'));

        $this->validateOverrides(Payload::object($config, 'overrides') ?? [], $definitions);
        $this->validateRetention(Payload::object($config, 'retention') ?? []);
        $this->validateConnections(Payload::object($config, 'connections') ?? []);
    }

    /**
     * @param  array<array-key, mixed>  $configured
     * @return array<string, Definition>
     */
    private function definitions(array $configured): array
    {
        $definitions = [];

        foreach (array_values($configured) as $index => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw InvalidConfigurationException::unknownDefinition($index, $class);
            }

            if (! is_subclass_of($class, SyncDefinition::class)
                && ! is_subclass_of($class, TransferDefinition::class)) {
                throw InvalidConfigurationException::definitionHasWrongType($index, $class);
            }

            /** @var Definition $definition */
            $definition = $this->container->make($class);
            $name = $definition->name();

            if (array_key_exists($name, $definitions)) {
                throw InvalidConfigurationException::duplicateDefinitionName($name);
            }

            $definitions[$name] = $definition;
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, Definition>  $definitions
     */
    private function validateOverrides(array $overrides, array $definitions): void
    {
        foreach ($overrides as $name => $settings) {
            if (! array_key_exists($name, $definitions)) {
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
