<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Product;

use function Orchestra\Testbench\workbench_path;

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

        $this->seedSupplierDatabase();
    }

    /**
     * The supplier's own database, which SupplierProductsSync reads. It is a
     * separate SQLite file, so it starts over on every seed.
     */
    private function seedSupplierDatabase(): void
    {
        $path = workbench_path('database', 'supplier.sqlite');

        if (! file_exists($path)) {
            touch($path);
        }

        $schema = Schema::connection('supplier');
        $schema->dropIfExists('supplier_products');
        $schema->create('supplier_products', function (Blueprint $table): void {
            $table->string('sku')->primary();
            $table->string('title');
            $table->unsignedInteger('price_cents');
            $table->dateTime('updated_at');
        });

        DB::connection('supplier')->table('supplier_products')->insert([
            ['sku' => 'SP-2001', 'title' => 'Oak desk', 'price_cents' => 24_900, 'updated_at' => '2026-11-09 08:00:00'],
            ['sku' => 'SP-2002', 'title' => 'Walnut shelf', 'price_cents' => 8_900, 'updated_at' => '2026-11-09 08:05:00'],
            ['sku' => 'SP-2003', 'title' => 'Task chair', 'price_cents' => 15_900, 'updated_at' => '2026-11-09 08:10:00'],
            ['sku' => 'SP-2004', 'title' => 'Monitor arm', 'price_cents' => 6_900, 'updated_at' => '2026-11-09 08:15:00'],
            ['sku' => 'SP-2005', 'title' => 'Cable tray', 'price_cents' => 1_900, 'updated_at' => '2026-11-09 08:20:00'],
        ]);
    }
}
