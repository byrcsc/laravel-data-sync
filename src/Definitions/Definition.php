<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

abstract class Definition
{
    abstract public function source(): Source;

    public function name(): string
    {
        $basename = class_basename(static::class);
        $name = Str::endsWith($basename, 'Sync')
            ? Str::beforeLast($basename, 'Sync')
            : $basename;

        return Str::kebab($name);
    }

    public function queueConnection(): ?string
    {
        return null;
    }

    public function queue(): ?string
    {
        return null;
    }

    public function schedule(Event $event): void {}
}
