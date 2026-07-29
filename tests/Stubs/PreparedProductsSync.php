<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class PreparedProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'prepared-products';
    }

    public function prepare(array $row): array
    {
        $row['name'] = 'Prepared '.trim((string) $row['name']);

        return $row;
    }
}
