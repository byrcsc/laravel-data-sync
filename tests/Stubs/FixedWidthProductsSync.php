<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Definitions\Reader;
use ByRcsc\LaravelDataSync\Definitions\Source;

final class FixedWidthProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'fixed-width-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')->path('products-fixed.txt');
    }

    public function format(): ReaderContract
    {
        return Reader::fixedWidth()
            ->column('SKU', start: 0, length: 8)
            ->column('NAME', start: 8, length: 24)
            ->column('PRICE', start: 32, length: 4);
    }
}
