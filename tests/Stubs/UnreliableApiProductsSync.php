<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

/**
 * The same definition as ApiProductsSync, with the backoff removed so failure
 * tests exercise the retry count without waiting for it.
 */
final class UnreliableApiProductsSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'api-products';
    }

    public function retryDelay(): int
    {
        return 0;
    }
}
