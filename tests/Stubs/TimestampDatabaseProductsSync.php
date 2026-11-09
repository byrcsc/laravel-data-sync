<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Watermark;

final class TimestampDatabaseProductsSync extends DatabaseProductSyncDefinition
{
    public function __construct(
        private readonly int $overlapSeconds = 0,
        private readonly string $column = 'UPDATED_AT',
    ) {}

    public function name(): string
    {
        return 'timestamp-products';
    }

    public function watermark(): Watermark
    {
        return Watermark::timestamp($this->column, overlapSeconds: $this->overlapSeconds);
    }
}
