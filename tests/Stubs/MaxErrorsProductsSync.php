<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class MaxErrorsProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'max-errors-products';
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function maxErrors(): ?int
    {
        return 1;
    }
}
