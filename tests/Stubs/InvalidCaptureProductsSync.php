<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;

final class InvalidCaptureProductsSync extends ProductSyncDefinition
{
    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Capture;
    }

    public function captureUnmappedInto(): ?string
    {
        return 'name';
    }
}
