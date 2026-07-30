<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\Definition;

final readonly class QueueOptions
{
    public function connection(Definition $definition): ?string
    {
        $override = config("data-sync.overrides.{$definition->name()}.queue.connection");
        $configured = is_string($override) ? $override : $definition->queueConnection();
        $configured ??= config('data-sync.queue.connection');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : null;
    }

    public function queue(Definition $definition): ?string
    {
        $override = config("data-sync.overrides.{$definition->name()}.queue.queue");
        $configured = is_string($override) ? $override : $definition->queue();
        $configured ??= config("data-sync.overrides.{$definition->name()}.queue");
        $configured = is_string($configured) ? $configured : config('data-sync.queue.queue');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : null;
    }
}
