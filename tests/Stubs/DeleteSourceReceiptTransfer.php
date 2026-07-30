<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Source;

final class DeleteSourceReceiptTransfer extends ReceiptTransferDefinition
{
    public function source(): Source
    {
        return parent::source()->deleteFromSource();
    }
}
