<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Watermark;
use Illuminate\Database\Query\Builder;

final class RowVersionProductsSync extends DatabaseProductSyncDefinition
{
    public function name(): string
    {
        return 'rowversion-products';
    }

    public function query(Builder $query): Builder
    {
        // RowVer is binary, so it stays out of the select. The package never
        // needs per-row values, because it reads up to a captured bound.
        return $query->from('source_versioned_products')->select(['SKU', 'NAME', 'PRICE']);
    }

    public function watermark(): Watermark
    {
        return Watermark::rowVersion('RowVer');
    }
}
