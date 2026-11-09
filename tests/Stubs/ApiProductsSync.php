<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class ApiProductsSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'api-products';
    }
}
