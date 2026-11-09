<?php

declare(strict_types=1);

namespace Workbench\App\Syncs;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Format;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use Workbench\App\Models\Product;

/**
 * The demo sync for the workbench app.
 *
 * A supplier drops a product price list into the `incoming` disk; each run
 * upserts it into `products` keyed on `sku`. The chunk size is deliberately
 * small so the queued path fans out into several jobs on a real worker.
 */
final class ProductsSync extends SyncDefinition
{
    private const string DEFAULT_CATEGORY = 'general';

    public function source(): Source
    {
        return Source::disk('incoming')->path('products');
    }

    public function format(): Reader
    {
        return Format::csv();
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            Field::make('sku', from: 'SKU'),
            Field::make('name', from: 'NAME'),
            Field::make('price', from: 'PRICE')
                ->transform(fn (?string $price): int => (int) round(((float) $price) * 100)),
            // A missing CATEGORY column and a blank CATEGORY cell are not the
            // same thing. `default()` covers the first — it only fires when the
            // column is absent from the file. A blank cell still arrives as an
            // empty string, so normalise that separately.
            Field::make('category', from: 'CATEGORY')
                ->optional()
                ->default(self::DEFAULT_CATEGORY)
                ->transform(fn (?string $category): string => match ($category) {
                    null, '' => self::DEFAULT_CATEGORY,
                    default => $category,
                }),
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

    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Capture;
    }

    public function captureUnmappedInto(): ?string
    {
        return 'meta';
    }

    public function chunkSize(): int
    {
        return 2;
    }
}
