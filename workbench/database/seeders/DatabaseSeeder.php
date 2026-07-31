<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Product;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Both of these SKUs also appear in the demo CSV, so the first
        // `sync:run` reports a mix of created and updated rows rather than a
        // uniform wall of inserts. Seeding is idempotent so it can be re-run
        // over an already-populated demo database.
        Product::query()->updateOrCreate(
            ['sku' => 'DS-1001'],
            ['name' => 'Placeholder desk lamp', 'price' => 1_000, 'category' => 'general'],
        );

        Product::query()->updateOrCreate(
            ['sku' => 'DS-1002'],
            ['name' => 'Placeholder keyboard', 'price' => 2_000, 'category' => 'general'],
        );

        Product::factory()->count(3)->create();
    }
}
