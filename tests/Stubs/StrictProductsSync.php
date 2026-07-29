<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;

final class StrictProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'strict-products';
    }

    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Strict;
    }
}
