<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class DatabaseProductsSync extends DatabaseProductSyncDefinition
{
    public function name(): string
    {
        return 'database-products';
    }
}
