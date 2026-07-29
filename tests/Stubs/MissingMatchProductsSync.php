<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class MissingMatchProductsSync extends ProductSyncDefinition
{
    public function matchOn(): array
    {
        return ['external_id'];
    }
}
