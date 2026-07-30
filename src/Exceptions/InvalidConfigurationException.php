<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;

final class InvalidConfigurationException extends DataSyncException
{
    public static function unknownDefinition(int $index, mixed $definition): self
    {
        $value = is_scalar($definition) ? (string) $definition : get_debug_type($definition);

        return new self("The [data-sync.syncs.{$index}] config value [{$value}] must be the name of a class that exists.");
    }

    public static function definitionHasWrongType(int $index, string $definition): self
    {
        return new self("The [data-sync.syncs.{$index}] config points at [{$definition}], which must extend ".SyncDefinition::class.' or '.TransferDefinition::class.'.');
    }

    public static function duplicateDefinitionName(string $name): self
    {
        return new self("The [data-sync.syncs] config registers more than one definition named [{$name}]. Override name() so every definition is unique.");
    }

    public static function unknownOverride(string $name): self
    {
        return new self("The [data-sync.overrides.{$name}] config does not match a registered sync definition.");
    }

    public static function invalidSchedule(string $name): self
    {
        return new self("The [data-sync.overrides.{$name}.schedule] config must be false or a valid cron expression.");
    }

    public static function invalidChunkSize(string $name): self
    {
        return new self("The [data-sync.overrides.{$name}.chunk_size] config must be an integer greater than zero.");
    }

    public static function nonOperationalOverride(string $name, string $key): self
    {
        return new self("The [data-sync.overrides.{$name}.{$key}] config is not allowed. Overrides may only use the operational keys [enabled, schedule, queue, chunk_size].");
    }

    public static function invalidRetention(string $key): self
    {
        return new self("The [data-sync.retention.{$key}] config must be null or a non-negative integer.");
    }

    public static function unknownConnection(string $name): self
    {
        return new self("The [data-sync.connections.{$name}] connection is not configured.");
    }

    public static function invalidConnection(string $name): self
    {
        return new self("The [data-sync.connections.{$name}] config must be an array containing a non-empty [driver].");
    }

    public static function missingAdapter(string $name, string $driver, string $package): self
    {
        return new self("The [data-sync.connections.{$name}] connection uses [{$driver}], which requires `composer require {$package}`.");
    }
}
