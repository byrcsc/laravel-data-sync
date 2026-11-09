<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;

final class ChunkSize
{
    /**
     * The `overrides.{name}.chunk_size` config wins over the definition's own
     * chunkSize(), for the inline and the queued path alike.
     */
    public static function for(SyncDefinition $definition): int
    {
        $configured = config("data-sync.overrides.{$definition->name()}.chunk_size");

        return is_int($configured) && $configured > 0
            ? $configured
            : $definition->chunkSize();
    }
}
