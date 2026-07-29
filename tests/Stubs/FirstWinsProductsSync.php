<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;

final class FirstWinsProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'first-wins-products';
    }

    public function onDuplicate(): DuplicatePolicy
    {
        return DuplicatePolicy::FirstWins;
    }
}
