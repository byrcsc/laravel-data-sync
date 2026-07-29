<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class QueuedProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'queued-products';
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function queueConnection(): ?string
    {
        return 'database';
    }

    public function queue(): ?string
    {
        return 'imports';
    }
}
