<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Enums\UnmatchedFilenamePolicy;

final class FailUnmatchedReceiptTransfer extends ReceiptTransferDefinition
{
    public function onUnmatched(): UnmatchedFilenamePolicy
    {
        return UnmatchedFilenamePolicy::Fail;
    }
}
