<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class BadEndpointApiSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'bad-endpoint';
    }

    public function endpoint(): string
    {
        return 'ftp://middleware.test/api/products';
    }
}
