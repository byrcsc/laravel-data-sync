<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class NegativeRetriesApiSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'negative-retries';
    }

    public function retries(): int
    {
        return -1;
    }
}
