<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DatabaseProductSyncDefinition;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\RowVersionProductsSync;
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
});

it('rejects a rowversion watermark on any driver but sqlsrv and names the connection', function (): void {
    config()->set('database.connections.lite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $definition = new class extends DatabaseProductSyncDefinition
    {
        public function connection(): string
        {
            return 'lite';
        }

        public function watermark(): Watermark
        {
            return Watermark::rowVersion('RowVer');
        }
    };

    expect(fn () => app(DefinitionValidator::class)->validate($definition))
        ->toThrow(InvalidDefinitionException::class, 'connection() returned [lite] with the [sqlite] driver');
});

describe('on a SQL Server source', function (): void {
    beforeEach(function (): void {
        if (sourceDriver() !== 'sqlsrv') {
            $this->markTestSkipped('Needs a SQL Server source (DB_SOURCE_DRIVER=sqlsrv).');
        }

        $source = DB::connection('source');

        // Snapshot reads keep the open transaction below from blocking the
        // fetch, which is the isolation the README recommends.
        $source->unprepared('ALTER DATABASE CURRENT SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE');
        $source->unprepared("IF OBJECT_ID('source_versioned_products') IS NOT NULL DROP TABLE source_versioned_products");
        $source->unprepared('CREATE TABLE source_versioned_products (SKU nvarchar(50) PRIMARY KEY, NAME nvarchar(255) NOT NULL, PRICE nvarchar(20) NOT NULL, RowVer rowversion NOT NULL)');
        $source->unprepared("IF OBJECT_ID('source_unrelated') IS NOT NULL DROP TABLE source_unrelated");
        $source->unprepared('CREATE TABLE source_unrelated (id int PRIMARY KEY, RowVer rowversion NOT NULL)');

        config()->set('data-sync.syncs', [RowVersionProductsSync::class, TimestampDatabaseProductsSync::class]);
    });

    function versionedProduct(string $sku, string $name, string $connection = 'source'): void
    {
        DB::connection($connection)->table('source_versioned_products')
            ->insert(['SKU' => $sku, 'NAME' => $name, 'PRICE' => '1.00']);
    }

    function runRowVersionSync(): SyncRun
    {
        return app(SyncManager::class)->run('rowversion-products')->runs[0]->run;
    }

    it('stores the bound as a hex string and compares it without casting the column', function (): void {
        versionedProduct('A-1', 'Alpha');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($query->connectionName === 'source') {
                $queries[] = $query->sql;
            }
        });

        runRowVersionSync();
        versionedProduct('B-2', 'Beta');
        $second = runRowVersionSync();
        $stored = app(TrackWatermark::class)->current('rowversion-products');

        expect($second->rows_total)->toBe(1)
            ->and($stored['mode'])->toBe('rowversion')
            ->and($stored['column'])->toBe('RowVer')
            ->and($stored['value'])->toMatch('/^0x[0-9A-F]{16}$/')
            ->and(implode("\n", $queries))
            ->toContain('[RowVer] < convert(binary(8), ?, 1)')
            ->toContain('[RowVer] >= convert(binary(8), ?, 1)');
    });

    it('reads a row committed after the run in the next run', function (): void {
        config()->set('database.connections.source_writer', config('database.connections.source'));
        versionedProduct('A-1', 'Alpha');
        $writer = DB::connection('source_writer');

        // The open transaction holds a rowversion below the next committed
        // one. Bounding at the highest committed value would skip it for
        // good once it commits.
        $writer->beginTransaction();
        versionedProduct('L-1', 'Late', 'source_writer');
        versionedProduct('B-2', 'Beta');

        $first = runRowVersionSync();
        $writer->commit();
        $second = runRowVersionSync();

        expect($first->rows_total)->toBe(1)
            ->and($second->rows_total)->toBe(2)
            ->and(Product::query()->orderBy('sku')->pluck('sku')->all())->toBe(['A-1', 'B-2', 'L-1']);
    });

    it('advances through a zero-row run when only another table changed', function (): void {
        versionedProduct('A-1', 'Alpha');
        runRowVersionSync();
        $before = app(TrackWatermark::class)->current('rowversion-products')['value'];

        DB::connection('source')->table('source_unrelated')->insert(['id' => 1]);
        $quiet = runRowVersionSync();
        $after = app(TrackWatermark::class)->current('rowversion-products')['value'];

        expect($quiet->status)->toBe(RunStatus::Completed)
            ->and($quiet->rows_total)->toBe(0)
            ->and(hexdec(substr($after, 2)))->toBeGreaterThan(hexdec(substr($before, 2)));
    });

    it('keeps all seven fractional digits of a datetime2 bound', function (): void {
        $source = DB::connection('source');
        $source->unprepared("IF OBJECT_ID('source_products') IS NOT NULL DROP TABLE source_products");
        $source->unprepared('CREATE TABLE source_products (SKU nvarchar(50) PRIMARY KEY, NAME nvarchar(255), PRICE nvarchar(20), META nvarchar(max), UPDATED_AT datetime2(7))');
        $source->table('source_products')->insert(
            ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00', 'UPDATED_AT' => '2026-11-09 10:00:00.1234567'],
        );

        app(SyncManager::class)->run('timestamp-products');
        $source->table('source_products')->insert(
            ['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '1.00', 'UPDATED_AT' => '2026-11-09 10:00:00.1234568'],
        );
        $second = app(SyncManager::class)->run('timestamp-products')->runs[0]->run;

        expect(app(TrackWatermark::class)->current('timestamp-products')['value'])->toEndWith('.1234568')
            ->and($second->rows_total)->toBe(1)
            ->and(Product::query()->count())->toBe(2);
    });

    it('compares a legacy datetime column with the raw bound', function (int $overlapSeconds, int $reread): void {
        app()->instance(TimestampDatabaseProductsSync::class, new TimestampDatabaseProductsSync(overlapSeconds: $overlapSeconds));
        $source = DB::connection('source');
        $source->unprepared("IF OBJECT_ID('source_products') IS NOT NULL DROP TABLE source_products");
        $source->unprepared('CREATE TABLE source_products (SKU nvarchar(50) PRIMARY KEY, NAME nvarchar(255), PRICE nvarchar(20), META nvarchar(max), UPDATED_AT datetime)');
        $source->table('source_products')->insert(
            ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00', 'UPDATED_AT' => '2026-11-09 10:00:00.123'],
        );

        app(SyncManager::class)->run('timestamp-products');
        $source->table('source_products')->insert(
            ['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '1.00', 'UPDATED_AT' => '2026-11-09 10:00:00.127'],
        );
        $second = app(SyncManager::class)->run('timestamp-products')->runs[0]->run;

        expect($second->status)->toBe(RunStatus::Completed)
            ->and($second->rows_total)->toBe(1 + $reread);
    })->with([
        'no overlap' => [0, 0],
        'an overlap' => [1, 1],
    ]);
});
