<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class BadKnobsApiSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'bad-knobs';
    }

    public function timeout(): int
    {
        return 0;
    }
}
