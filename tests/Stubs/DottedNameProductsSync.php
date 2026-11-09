<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class DottedNameProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'products.daily';
    }
}
