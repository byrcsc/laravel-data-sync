<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Field;

final class UnknownAttributeProductsSync extends ProductSyncDefinition
{
    public function fields(): array
    {
        return [
            Field::make('sku', from: 'SKU'),
            Field::make('not_a_product_attribute', from: 'UNKNOWN'),
        ];
    }
}
