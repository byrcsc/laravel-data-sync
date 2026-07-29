<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class CompositeProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'composite-products';
    }

    public function matchOn(): array
    {
        return ['sku', 'category'];
    }

    public function model(): string
    {
        return CompositeProduct::class;
    }
}
