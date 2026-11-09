<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\DatabaseFetchException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\TimestampDatabaseProductsSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
        $table->json('meta')->nullable();
    });

    Storage::fake('staging');
    Storage::fake('archive');
    Storage::fake('failed');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.failed.disk', 'failed');
    config()->set('data-sync.syncs', [TimestampDatabaseProductsSync::class]);

    createSourceProducts();
});

function sourceProduct(string $sku, string $name, ?string $updatedAt): array
{
    return ['SKU' => $sku, 'NAME' => $name, 'PRICE' => '1.00', 'META' => null, 'UPDATED_AT' => $updatedAt];
}

function addSourceProduct(string $sku, string $name, ?string $updatedAt): void
{
    DB::connection('source')->table('source_products')->insert(sourceProduct($sku, $name, $updatedAt));
}

function runTimestampSync(): SyncRun
{
    return app(SyncManager::class)->run('timestamp-products')->runs[0]->run;
}

function storedWatermark(): mixed
{
    return app(TrackWatermark::class)->current('timestamp-products');
}

it('reads every row first and then only the rows inside the window', function (): void {
    addSourceProduct('A-1', 'Alpha', '2026-11-09 10:00:00');
    addSourceProduct('B-2', 'Beta', '2026-11-09 10:01:00');
    addSourceProduct('N-0', 'Never stamped', null);

    $first = runTimestampSync();
    // The value is the string the driver returned for MAX(), never parsed.
    $raw = DB::connection('source')->table('source_products')->max('UPDATED_AT');

    expect($first->rows_total)->toBe(3)
        // MySQL's JSON column reorders keys, so the comparison ignores order.
        ->and(storedWatermark())->toEqual(['mode' => 'timestamp', 'column' => 'UPDATED_AT', 'value' => $raw]);

    addSourceProduct('C-3', 'Gamma', '2026-11-09 10:02:00');
    $second = runTimestampSync();

    expect($second->rows_total)->toBe(1)
        ->and($second->rows_created)->toBe(1)
        ->and(Product::query()->count())->toBe(4);
});

it('re-reads the overlap below the bound and upserts it without duplicates', function (): void {
    app()->instance(TimestampDatabaseProductsSync::class, new TimestampDatabaseProductsSync(overlapSeconds: 300));
    addSourceProduct('A-1', 'Alpha', '2026-11-09 09:00:00');
    addSourceProduct('B-2', 'Beta', '2026-11-09 10:00:00');
    addSourceProduct('C-3', 'Gamma', '2026-11-09 10:04:00');
    runTimestampSync();

    addSourceProduct('D-4', 'Delta', '2026-11-09 10:06:00');
    $second = runTimestampSync();

    // The window is (10:04 - 5 minutes, 10:06], so B and C are read again and A
    // is not.
    expect($second->rows_total)->toBe(3)
        ->and($second->rows_created)->toBe(1)
        ->and($second->rows_updated)->toBe(2)
        ->and(Product::query()->count())->toBe(4);
});

it('keeps the bound where it was when no row is above it', function (): void {
    addSourceProduct('A-1', 'Alpha', '2026-11-09 10:00:00');
    runTimestampSync();
    $bound = storedWatermark();

    $quiet = runTimestampSync();

    expect($quiet->status)->toBe(RunStatus::Completed)
        ->and($quiet->rows_total)->toBe(0)
        ->and(storedWatermark())->toEqual($bound);
});

it('reads a row updated during the read in the next run', function (): void {
    addSourceProduct('A-1', 'Alpha', '2026-11-09 10:00:00');
    addSourceProduct('B-2', 'Beta', '2026-11-09 10:01:00');
    addSourceProduct('C-3', 'Gamma', '2026-11-09 10:02:00');
    $source = DB::connection('source');
    $updated = false;

    // After the first page (A and B) is read, A changes, and so does C, which
    // the fetch has not reached yet. Under a "highest value seen" watermark,
    // reading C at 10:06 would move the watermark past A's change at 10:05.
    $source->listen(function (QueryExecuted $query) use ($source, &$updated): void {
        // Connection listeners are global, so the package's own queries arrive
        // here too.
        if ($updated || $query->connectionName !== 'source' || ! str_contains(strtolower($query->sql), 'order by')) {
            return;
        }

        $updated = true;
        $source->table('source_products')->where('SKU', 'A-1')
            ->update(['NAME' => 'Alpha v2', 'UPDATED_AT' => '2026-11-09 10:05:00']);
        $source->table('source_products')->where('SKU', 'C-3')
            ->update(['NAME' => 'Gamma v2', 'UPDATED_AT' => '2026-11-09 10:06:00']);
    });

    runTimestampSync();
    runTimestampSync();

    expect(Product::query()->orderBy('sku')->pluck('name', 'sku')->all())
        ->toBe(['A-1' => 'Alpha v2', 'B-2' => 'Beta', 'C-3' => 'Gamma v2']);
});

it('fails the fetch and names the row to delete when the watermark changes', function (array $stored): void {
    app(TrackWatermark::class)->advance('timestamp-products', $stored);
    addSourceProduct('A-1', 'Alpha', '2026-11-09 10:00:00');

    expect(fn () => runTimestampSync())->toThrow(
        DatabaseFetchException::class,
        'Delete the [sync_watermarks] row where definition is [timestamp-products]',
    );

    expect(storedWatermark())->toEqual($stored);
})->with([
    'a different column' => [['mode' => 'timestamp', 'column' => 'CREATED_AT', 'value' => '2026-11-09 10:00:00']],
    'a different mode' => [['mode' => 'rowversion', 'column' => 'UPDATED_AT', 'value' => '0x00000000000007D1']],
]);
