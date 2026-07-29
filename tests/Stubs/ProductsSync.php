<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class ProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'products';
    }
}
