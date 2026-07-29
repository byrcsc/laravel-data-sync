<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Source;

final class YoungProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'young-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')
            ->path('young-products.csv')
            ->minFileAge(minutes: 5);
    }
}
