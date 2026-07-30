<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Destination;
use ByRcsc\LaravelDataSync\Enums\ExistingFilePolicy;

final class FailExistingReceiptTransfer extends ReceiptTransferDefinition
{
    public function destination(): Destination
    {
        return parent::destination()->onExisting(ExistingFilePolicy::Fail);
    }
}
