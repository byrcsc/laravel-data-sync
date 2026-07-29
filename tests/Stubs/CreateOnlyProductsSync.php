<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\WriteMode;

final class CreateOnlyProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'create-only-products';
    }

    public function writeMode(): WriteMode
    {
        return WriteMode::CreateOnly;
    }
}
