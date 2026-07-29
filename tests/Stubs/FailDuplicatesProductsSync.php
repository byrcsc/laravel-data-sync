<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;

final class FailDuplicatesProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'fail-duplicates-products';
    }

    public function onDuplicate(): DuplicatePolicy
    {
        return DuplicatePolicy::Fail;
    }
}
