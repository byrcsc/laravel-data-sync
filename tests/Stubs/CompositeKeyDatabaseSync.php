<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use Illuminate\Database\Query\Builder;

final class CompositeKeyDatabaseSync extends DatabaseSyncDefinition
{
    public function name(): string
    {
        return 'product-lines';
    }

    public function connection(): string
    {
        return 'source';
    }

    public function query(Builder $query): Builder
    {
        // The ordering is replaced by the key order.
        return $query->from('source_product_lines')->orderByDesc('NAME');
    }

    public function keyColumns(): array
    {
        return ['DocEntry', 'LineNum'];
    }

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
        ];
    }

    public function matchOn(): array
    {
        return ['sku'];
    }
}
