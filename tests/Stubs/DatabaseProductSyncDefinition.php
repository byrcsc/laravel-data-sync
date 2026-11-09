<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use Illuminate\Database\Query\Builder;

abstract class DatabaseProductSyncDefinition extends DatabaseSyncDefinition
{
    public function connection(): string
    {
        return 'source';
    }

    public function query(Builder $query): Builder
    {
        return $query->from('source_products')
            ->select(['SKU', 'NAME', 'PRICE', 'META', 'UPDATED_AT']);
    }

    public function keyColumns(): array
    {
        return ['SKU'];
    }

    /**
     * Small pages, so every test crosses a page boundary.
     */
    public function pageSize(): int
    {
        return 2;
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
            Field::make('meta', from: 'META')->optional(),
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
