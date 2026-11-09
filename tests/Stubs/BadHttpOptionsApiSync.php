<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class BadHttpOptionsApiSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'bad-http-options';
    }

    public function timeout(): int
    {
        return 0;
    }
}
