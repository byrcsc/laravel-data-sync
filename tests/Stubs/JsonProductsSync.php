<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Definitions\Reader;
use ByRcsc\LaravelDataSync\Definitions\Source;

final class JsonProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'json-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')->path('products.json');
    }

    public function format(): ReaderContract
    {
        return Reader::json()->path('payload.products');
    }
}
