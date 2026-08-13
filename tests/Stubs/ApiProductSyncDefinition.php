<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;

abstract class ApiProductSyncDefinition extends ApiSyncDefinition
{
    public function endpoint(): string
    {
        return 'https://middleware.test/api/products';
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            Field::make('sku', from: 'SKU'),
            Field::make('name', from: 'NAME'),
            Field::make('price', from: 'PRICE')
                ->transform(fn (?string $price): int => (int) round(((float) $price) * 100)),
            Field::make('category', from: 'CATEGORY')->optional()->default('general'),
        ];
    }

    public function matchOn(): array
    {
        return ['sku'];
    }
}
