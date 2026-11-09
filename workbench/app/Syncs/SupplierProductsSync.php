<?php

declare(strict_types=1);

namespace Workbench\App\Syncs;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use Illuminate\Database\Query\Builder;
use Workbench\App\Models\Product;

/**
 * The database demo for the workbench app.
 *
 * The supplier keeps its catalogue in its own database, the `supplier`
 * connection. Each run reads only the rows whose `updated_at` moved since the
 * last completed run, and upserts them into `products` keyed on `sku`.
 */
final class SupplierProductsSync extends DatabaseSyncDefinition
{
    public function connection(): string
    {
        return 'supplier';
    }

    public function query(Builder $query): Builder
    {
        return $query->from('supplier_products')
            ->select(['sku', 'title', 'price_cents', 'updated_at']);
    }

    public function keyColumns(): array
    {
        return ['sku'];
    }

    public function watermark(): Watermark
    {
        return Watermark::timestamp('updated_at', overlapSeconds: 60);
    }

    /**
     * Small pages, so a demo run shows the keyset paging at work.
     */
    public function pageSize(): int
    {
        return 2;
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            Field::make('sku', from: 'sku'),
            Field::make('name', from: 'title'),
            Field::make('price', from: 'price_cents')
                ->transform(fn (?string $cents): int => (int) $cents),
        ];
    }

    public function matchOn(): array
    {
        return ['sku'];
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string'],
            'name' => ['required', 'string'],
            'price' => ['integer', 'min:0'],
        ];
    }
}
