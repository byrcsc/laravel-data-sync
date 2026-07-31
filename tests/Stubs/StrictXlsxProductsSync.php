<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Definitions\Reader;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;

final class StrictXlsxProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'strict-xlsx-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')->path('strict-products.xlsx');
    }

    public function format(): ReaderContract
    {
        return Reader::xlsx();
    }

    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Strict;
    }
}
