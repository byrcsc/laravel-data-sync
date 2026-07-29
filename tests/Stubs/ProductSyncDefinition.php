<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Reader;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;

abstract class ProductSyncDefinition extends SyncDefinition
{
    public function source(): Source
    {
        return Source::disk('incoming')->path($this->name().'.csv');
    }

    public function format(): ReaderContract
    {
        return Reader::csv();
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

    public function rules(): array
    {
        return [
            'sku' => ['required'],
            'name' => ['required'],
            'price' => ['integer', 'min:0'],
        ];
    }
}
