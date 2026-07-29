<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class HookedProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'hooked-products';
    }

    public function beforeSave(Model $model, array $row): mixed
    {
        return $row['name'] !== 'Skip me';
    }

    public function afterSave(Model $model, array $row): void
    {
        $model->setAttribute('name', strtoupper((string) $row['name']));
        $model->saveQuietly();
    }
}
