<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class AtomicProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'atomic-products';
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function atomic(): bool
    {
        return true;
    }

    public function afterSave(Model $model, array $row): void
    {
        if ($row['name'] === 'Explode') {
            throw new RuntimeException('Atomic hook exploded.');
        }
    }
}
