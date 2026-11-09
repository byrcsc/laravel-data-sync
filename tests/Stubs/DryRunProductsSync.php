<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Source;
use Illuminate\Database\Eloquent\Model;

final class DryRunProductsSync extends ProductSyncDefinition
{
    public static int $prepared = 0;

    public static int $beforeSaved = 0;

    public static int $afterSaved = 0;

    public function name(): string
    {
        return 'dry-run-products';
    }

    public function source(): Source
    {
        return Source::disk('incoming')
            ->path('dry-run-products.csv')
            ->deleteOriginal();
    }

    public function prepare(array $row): array
    {
        self::$prepared++;

        return $row;
    }

    public function beforeSave(Model $model, array $row): mixed
    {
        self::$beforeSaved++;

        return null;
    }

    public function afterSave(Model $model, array $row): void
    {
        self::$afterSaved++;
    }
}
