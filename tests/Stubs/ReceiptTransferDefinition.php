<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Data\TransferredFile;
use ByRcsc\LaravelDataSync\Definitions\Destination;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;

abstract class ReceiptTransferDefinition extends TransferDefinition
{
    public static ?TransferredFile $transferred = null;

    public function source(): Source
    {
        return Source::disk('incoming')->path('receipts');
    }

    public function filenamePattern(): string
    {
        return '{unit_no}_{contract_no}_{date:Ymd}.pdf';
    }

    public function destination(): Destination
    {
        return Destination::disk('receipts')
            ->path('{unit_no}/{date}/{filename}');
    }

    public function model(): ?string
    {
        return Receipt::class;
    }

    public function fields(): array
    {
        return [
            Field::make('unit_no'),
            Field::make('contract_no'),
            Field::make('path'),
            Field::make('checksum'),
            Field::make('size'),
        ];
    }

    public function matchOn(): array
    {
        return ['contract_no'];
    }

    public function afterTransfer(TransferredFile $file): void
    {
        self::$transferred = $file;
    }
}
