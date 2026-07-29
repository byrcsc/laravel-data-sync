<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Source;

final class DirectoryProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'directory-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')->path('inbox');
    }
}
