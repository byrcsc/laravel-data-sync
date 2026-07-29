<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\WriteMode;

final class UpdateOnlyProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'update-only-products';
    }

    public function writeMode(): WriteMode
    {
        return WriteMode::UpdateOnly;
    }
}
