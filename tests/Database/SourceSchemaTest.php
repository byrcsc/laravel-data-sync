<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\DatabaseFetchException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DatabaseProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\SchemaCheckedProductsSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
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

    createSourceProducts([
        ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00', 'META' => null, 'UPDATED_AT' => '2026-11-09 10:00:00'],
    ]);

    $schema = Schema::connection('source');
    $schema->dropIfExists('source_blobs');
    $schema->create('source_blobs', function (Blueprint $table): void {
        $table->string('SKU')->primary();
        $table->string('NAME');
        $table->string('PRICE');
        $table->binary('BLOB')->nullable();
    });
    DB::connection('source')->table('source_blobs')->insert(['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']);

    $this->sourceQueries = [];
    DB::listen(function (QueryExecuted $query): void {
        if ($query->connectionName === 'source') {
            $this->sourceQueries[] = $query->sql;
        }
    });
});

function register(SchemaCheckedProductsSync $definition): void
{
    config()->set('data-sync.syncs', [$definition::class]);
    app()->instance($definition::class, $definition);
}

/**
 * Queries that read a source table's rows, as opposed to its schema.
 */
function dataQueries(): array
{
    return array_values(array_filter(
        test()->sourceQueries,
        fn (string $sql): bool => preg_match('/from\s+[\["`]?source_(products|blobs)\b/i', $sql) === 1,
    ));
}

it('fails the fetch before reading rows and reports the same problem in doctor', function (SchemaCheckedProductsSync $definition, string $problem): void {
    register($definition);

    expect(fn () => app(SyncManager::class)->run('schema-products'))
        ->toThrow(DatabaseFetchException::class, $problem)
        ->and(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed)
        ->and(dataQueries())->toBe([]);

    $this->sourceQueries = [];

    $this->artisan('sync:doctor')
        ->expectsOutputToContain($problem)
        ->assertFailed();

    // Doctor reads the schema and never the table's rows.
    expect(dataQueries())->toBe([]);
})->with([
    'a field reading a missing column' => [
        fn () => new class extends SchemaCheckedProductsSync
        {
            public function fields(): array
            {
                return [Field::make('sku', from: 'SKU'), Field::make('name', from: 'TITLE')];
            }
        },
        'fields() maps [name] from column [TITLE], which query() does not select from [source_products].',
    ],
    'a missing key column' => [
        fn () => new class extends SchemaCheckedProductsSync
        {
            public function keyColumns(): array
            {
                return ['CODE'];
            }
        },
        'keyColumns() names [CODE], which [source_products] does not have.',
    ],
    'a missing watermark column' => [
        fn () => new class extends SchemaCheckedProductsSync
        {
            public function watermark(): Watermark
            {
                return Watermark::timestamp('CHANGED_AT');
            }
        },
        'watermark() names column [CHANGED_AT], which [source_products] does not have.',
    ],
    'a watermark column of the wrong type' => [
        fn () => new class extends SchemaCheckedProductsSync
        {
            public function watermark(): Watermark
            {
                return Watermark::timestamp('NAME');
            }
        },
        'watermark() reads column [NAME] as a timestamp watermark, but [source_products] stores it as [',
    ],
    'a binary column in the select' => [
        fn () => new class extends SchemaCheckedProductsSync
        {
            public function query(Builder $query): Builder
            {
                return $query->from('source_blobs')->select(['SKU', 'NAME', 'PRICE', 'BLOB']);
            }
        },
        'query() selects the binary column [BLOB]',
    ],
]);

it('checks the first page of a union instead of the schema', function (): void {
    register(new class extends SchemaCheckedProductsSync
    {
        public function query(Builder $query): Builder
        {
            return $query->from('source_products')->select(['SKU', 'NAME', 'PRICE'])
                ->union(DB::connection('source')->table('source_blobs')->select(['SKU', 'NAME', 'PRICE']));
        }

        public function fields(): array
        {
            return [Field::make('sku', from: 'SKU'), Field::make('name', from: 'TITLE')];
        }
    });

    expect(fn () => app(SyncManager::class)->run('schema-products'))->toThrow(
        DatabaseFetchException::class,
        'fields() maps [name] from column [TITLE], but the rows query() returns have no such column.',
    );

    $this->artisan('sync:doctor')
        ->doesntExpectOutputToContain('TITLE');
});

it('reports an unreachable source in doctor without affecting boot', function (): void {
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => '/nonexistent/data-sync-source.sqlite',
        'prefix' => '',
    ]);
    DB::purge('source');
    config()->set('data-sync.syncs', [DatabaseProductsSync::class]);

    expect(array_keys(app(SyncManager::class)->registered()))->toBe(['database-products']);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('The [source] connection for definition [database-products] could not be reached')
        ->assertFailed();
});
