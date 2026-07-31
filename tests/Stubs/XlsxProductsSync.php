<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Definitions\Reader;
use ByRcsc\LaravelDataSync\Definitions\Source;

final class XlsxProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'xlsx-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')->path('products.xlsx');
    }

    public function format(): ReaderContract
    {
        return Reader::xlsx();
    }
}
