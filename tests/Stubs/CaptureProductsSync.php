<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;

final class CaptureProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'capture-products';
    }

    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Capture;
    }

    public function captureUnmappedInto(): ?string
    {
        return 'meta';
    }
}
