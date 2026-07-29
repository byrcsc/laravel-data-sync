<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Source;

final class DeleteSourceProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'delete-source-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')
            ->path('delete-source-products.csv')
            ->deleteFromSource();
    }
}
