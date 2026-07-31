<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Tests\Stubs\FixedWidthProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\JsonProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\NdjsonProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\StrictXlsxProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\XlsxProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
    });

    Storage::fake('incoming');
    Storage::fake('staging');
    config()->set('data-sync.staging.disk', 'staging');
});

function xlsxFixture(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'data-sync-xlsx-');

    if ($path === false) {
        throw new RuntimeException('Could not create an XLSX fixture file.');
    }

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRows(array_map(Row::fromValues(...), $rows));
    $writer->close();
    $contents = file_get_contents($path);
    unlink($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read the XLSX fixture file.');
    }

    return $contents;
}

it('imports JSON rows from a configured document path through the full pipeline', function (): void {
    config()->set('data-sync.syncs', [JsonProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.json',
        file_get_contents(__DIR__.'/../Fixtures/products.json'),
    );

    $this->artisan('sync:run', ['name' => 'json-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->pluck('name', 'sku')->all())->toBe([
        'J-1' => 'JSON product',
        'J-2' => 'Second JSON product',
    ])->and(Product::query()->where('sku', 'J-1')->value('price'))->toBe(1250);
});

it('streams NDJSON rows through the full pipeline', function (): void {
    config()->set('data-sync.syncs', [NdjsonProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.ndjson',
        file_get_contents(__DIR__.'/../Fixtures/products.ndjson'),
    );

    $this->artisan('sync:run', ['name' => 'ndjson-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->pluck('name', 'sku')->all())->toBe([
        'N-1' => 'NDJSON product',
        'N-2' => 'Second NDJSON product',
    ])->and(Product::query()->where('sku', 'N-2')->value('price'))->toBe(475);
});

it('imports configured fixed-width columns through the full pipeline', function (): void {
    config()->set('data-sync.syncs', [FixedWidthProductsSync::class]);
    Storage::disk('incoming')->put(
        'products-fixed.txt',
        file_get_contents(__DIR__.'/../Fixtures/products-fixed.txt'),
    );

    $this->artisan('sync:run', ['name' => 'fixed-width-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->pluck('name', 'sku')->all())->toBe([
        'F-1' => 'Fixed width product',
        'F-2' => 'Second fixed product',
    ])->and(Product::query()->where('sku', 'F-2')->value('price'))->toBe(825);
});

it('streams an XLSX workbook through the full pipeline', function (): void {
    config()->set('data-sync.syncs', [XlsxProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.xlsx',
        file_get_contents(__DIR__.'/../Fixtures/products.xlsx'),
    );

    $this->artisan('sync:run', ['name' => 'xlsx-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->pluck('name', 'sku')->all())->toBe([
        'X-1' => 'XLSX product',
        'X-2' => 'Second XLSX product',
    ])->and(Product::query()->where('sku', 'X-2')->value('price'))->toBe(925);
});

it('applies header fail-fast and unknown-column policies to XLSX workbooks', function (): void {
    config()->set('data-sync.syncs', [StrictXlsxProductsSync::class]);
    Storage::disk('incoming')->put('strict-products.xlsx', xlsxFixture([
        ['SKU', 'PRICE'],
        ['X-1', 6.50],
    ]));

    $this->artisan('sync:run', ['name' => 'strict-xlsx-products', '--now' => true])
        ->expectsOutputToContain('Actual header: [SKU, PRICE]')
        ->assertFailed();

    Storage::disk('incoming')->put('strict-products.xlsx', xlsxFixture([
        ['SKU', 'NAME', 'PRICE', 'EXTRA'],
        ['X-2', 'Strict XLSX product', 9.25, 'unknown'],
    ]));

    $this->artisan('sync:run', [
        'name' => 'strict-xlsx-products',
        '--now' => true,
        '--force' => true,
    ])->expectsOutputToContain('unknown source columns [EXTRA]')
        ->assertFailed();
});
