<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\FetchDatabaseSnapshot;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\DatabaseFetchException;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Tests\Stubs\CompositeKeyDatabaseSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\DatabaseProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\DatabaseProductSyncDefinition;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
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
    config()->set('data-sync.syncs', [DatabaseProductsSync::class, CompositeKeyDatabaseSync::class]);

    // Inserted out of key order, so the result depends on the package's
    // ordering rather than on insertion order.
    createSourceProducts([
        ['SKU' => 'E-5', 'NAME' => 'Epsilon', 'PRICE' => '5.00', 'META' => null, 'UPDATED_AT' => null],
        ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00', 'META' => '{"colour":"red"}', 'UPDATED_AT' => null],
        ['SKU' => 'C-3', 'NAME' => 'Gamma', 'PRICE' => '3.00', 'META' => null, 'UPDATED_AT' => null],
        ['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '2.00', 'META' => null, 'UPDATED_AT' => null],
        ['SKU' => 'D-4', 'NAME' => 'Delta', 'PRICE' => '4.00', 'META' => null, 'UPDATED_AT' => null],
    ]);
});

it('syncs a source table into the model inline', function (): void {
    $this->artisan('sync:run', ['name' => 'database-products', '--now' => true])
        ->assertSuccessful();

    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(5)
        ->and($run->rows_created)->toBe(5)
        ->and(Product::query()->orderBy('sku')->pluck('price', 'sku')->all())
        ->toBe(['A-1' => 100, 'B-2' => 200, 'C-3' => 300, 'D-4' => 400, 'E-5' => 500])
        // A JSON text column bound for an array-cast attribute is stored once.
        ->and(Product::query()->where('sku', 'A-1')->firstOrFail()->meta)->toBe(['colour' => 'red']);

    $this->artisan('sync:status', ['name' => 'database-products'])
        ->expectsOutputToContain('completed')
        ->assertSuccessful();
});

it('syncs a source table through the queue', function (): void {
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->text('failed_job_ids');
        $table->text('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    config()->set('data-sync.queue.connection', 'sync');
    config()->set('queue.batching.database', 'testing');

    $this->artisan('sync:run', ['name' => 'database-products'])
        ->assertSuccessful();

    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Completed)
        ->and(Product::query()->count())->toBe(5);
});

it('pages a composite key across page boundaries', function (): void {
    $schema = Schema::connection('source');
    $schema->dropIfExists('source_product_lines');
    $schema->create('source_product_lines', function (Blueprint $table): void {
        $table->integer('DocEntry');
        $table->integer('LineNum');
        $table->string('SKU');
        $table->string('NAME');
        $table->primary(['DocEntry', 'LineNum']);
    });

    // Two rows share DocEntry 1 on either side of the first page boundary, so
    // paging on DocEntry alone would lose (1, 2).
    $lines = [[3, 5], [1, 2], [2, 0], [1, 0], [1, 1]];
    DB::connection('source')->table('source_product_lines')->insert(array_map(
        fn (array $line): array => [
            'DocEntry' => $line[0],
            'LineNum' => $line[1],
            'SKU' => "{$line[0]}-{$line[1]}",
            'NAME' => "Line {$line[0]}-{$line[1]}",
        ],
        $lines,
    ));

    $this->artisan('sync:run', ['name' => 'product-lines', '--now' => true])
        ->assertSuccessful();

    expect(SyncRun::query()->sole()->rows_total)->toBe(5)
        ->and(Product::query()->orderBy('sku')->pluck('sku')->all())
        ->toBe(['1-0', '1-1', '1-2', '2-0', '3-5']);
});

it('fails the run and names a binary value the schema check cannot see', function (): void {
    $schema = Schema::connection('source');
    $schema->dropIfExists('source_blobs');
    $schema->create('source_blobs', function (Blueprint $table): void {
        $table->string('SKU')->primary();
        $table->string('NAME');
        $table->binary('BLOB');
    });
    DB::connection('source')->table('source_blobs')->insert([
        'SKU' => 'A-1',
        'NAME' => 'Alpha',
        'BLOB' => DB::raw(match (sourceDriver()) {
            'pgsql' => "decode('fffe', 'hex')",
            'sqlsrv' => '0xFFFE',
            default => "X'FFFE'",
        }),
    ]);

    $definition = new class extends DatabaseProductSyncDefinition
    {
        public function query(Builder $query): Builder
        {
            // A raw select hides the column type from the schema check, so the
            // value itself is what fails.
            $blob = $query->getGrammar()->wrap('BLOB');

            return $query->from('source_blobs')->select(['SKU', 'NAME', DB::raw($blob)]);
        }
    };

    expect(fn () => app(FetchDatabaseSnapshot::class)($definition))
        ->toThrow(DatabaseFetchException::class, 'binary value from column [BLOB]')
        ->and(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed)
        ->and(SyncRun::query()->sole()->error_summary)->toContain('CONVERT(varchar(max), BLOB, 1)');
});

it('rejects at boot a definition the package cannot page', function (DatabaseProductSyncDefinition $definition, string $method): void {
    expect(fn () => app(DefinitionValidator::class)->validate($definition))
        ->toThrow(InvalidDefinitionException::class, $method);
})->with([
    'an unknown connection' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function connection(): string
            {
                return 'missing';
            }
        },
        'connection()',
    ],
    'no key columns' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function keyColumns(): array
            {
                return [];
            }
        },
        'keyColumns()',
    ],
    'a limit' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function query(Builder $query): Builder
            {
                return parent::query($query)->limit(10);
            }
        },
        'query() sets a limit or offset',
    ],
    'an offset' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function query(Builder $query): Builder
            {
                return parent::query($query)->offset(10);
            }
        },
        'query() sets a limit or offset',
    ],
    'a zero page size' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function pageSize(): int
            {
                return 0;
            }
        },
        'pageSize()',
    ],
    'a zero lock timeout' => [
        fn () => new class extends DatabaseProductSyncDefinition
        {
            public function lockTimeout(): int
            {
                return 0;
            }
        },
        'lockTimeout()',
    ],
]);

it('validates at boot without opening the source connection', function (): void {
    config()->set('database.connections.source.database', '/nonexistent/source.sqlite');
    config()->set('database.connections.source.driver', 'sqlite');

    app(DefinitionValidator::class)->validate(new DatabaseProductsSync);

    expect(true)->toBeTrue();
});
