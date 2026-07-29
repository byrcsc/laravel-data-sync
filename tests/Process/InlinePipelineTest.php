<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\AtomicProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\CaptureProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ChunkedProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\CompositeProduct;
use ByRcsc\LaravelDataSync\Tests\Stubs\CompositeProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\CreateOnlyProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\FailDuplicatesProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\FirstWinsProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\HookedProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\PreparedProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\StrictProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\UpdateOnlyProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ValidatorProductsSync;
use Illuminate\Database\Schema\Blueprint;
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

    Schema::create('composite_products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku');
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category');
        $table->unique(['sku', 'category']);
    });

    Storage::fake('incoming');
    Storage::fake('staging');
    config()->set('data-sync.staging.disk', 'staging');
});

it('imports a CSV synchronously through the command using the bulk path', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.csv',
        file_get_contents(__DIR__.'/../Fixtures/products.csv'),
    );

    $this->artisan('sync:run', ['name' => 'products', '--now' => true])
        ->assertSuccessful();

    $run = SyncRun::query()->sole();

    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('sku', 'A-1')->value('name'))->toBe('Alpha revised')
        ->and(Product::query()->where('sku', 'A-1')->value('price'))->toBe(1300)
        ->and(Product::query()->where('sku', 'C-3')->value('price'))->toBe(999)
        ->and($run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->write_path)->toBe(WritePath::Bulk)
        ->and($run->rows_total)->toBe(4)
        ->and($run->rows_created)->toBe(2)
        ->and($run->rows_skipped)->toBe(1)
        ->and($run->rows_failed)->toBe(1)
        ->and(SyncRowFailure::query()->value('row_number'))->toBe(4);
});

it('fails on a missing required header before writing rows and reports the actual header', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,PRICE\nA-1,1.00\n",
    );

    $this->artisan('sync:run', ['name' => 'products', '--now' => true])
        ->expectsOutputToContain('Actual header: [SKU, PRICE]')
        ->assertFailed();

    expect(Product::query()->count())->toBe(0)
        ->and(SyncRun::query()->value('status'))->toBe(RunStatus::Failed);
});

it('enforces strict unknown columns and captures raw unknown values with replacement semantics', function (): void {
    config()->set('data-sync.syncs', [StrictProductsSync::class]);
    Storage::disk('incoming')->put(
        'strict-products.csv',
        "SKU,NAME,PRICE,EXTRA\nA-1,Alpha,1.00,raw\n",
    );

    expect(fn () => app(SyncManager::class)->run('strict-products'))
        ->toThrow(RuntimeException::class, 'unknown source columns [EXTRA]');
    expect(Product::query()->count())->toBe(0);

    Product::query()->create([
        'sku' => 'A-1',
        'name' => 'Old',
        'price' => 1,
        'category' => 'old',
        'meta' => ['old' => 'value'],
    ]);
    config()->set('data-sync.syncs', [CaptureProductsSync::class]);
    Storage::disk('incoming')->put(
        'capture-products.csv',
        "SKU,NAME,PRICE,EXTRA\nA-1,Alpha,1.25,raw string\n",
    );

    app(SyncManager::class)->run('capture-products');

    expect(Product::query()->where('sku', 'A-1')->firstOrFail()->meta)
        ->toBe(['EXTRA' => 'raw string']);
});

it('uses Eloquent events and hooks when row hooks are defined', function (): void {
    $saved = 0;
    Product::saved(function () use (&$saved): void {
        $saved++;
    });
    config()->set('data-sync.syncs', [HookedProductsSync::class]);
    Storage::disk('incoming')->put(
        'hooked-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\nB-2,Skip me,2.00\n",
    );

    $report = app(SyncManager::class)->run('hooked-products')->runs[0];

    expect($saved)->toBe(1)
        ->and($report->writePath)->toBe(WritePath::Eloquent)
        ->and($report->created)->toBe(1)
        ->and($report->skipped)->toBe(1)
        ->and(Product::query()->where('sku', 'A-1')->value('name'))->toBe('ALPHA')
        ->and(Product::query()->where('sku', 'B-2')->exists())->toBeFalse();
});

it('honors first-wins, duplicate-fail, and composite identity policies', function (): void {
    config()->set('data-sync.syncs', [FirstWinsProductsSync::class]);
    Storage::disk('incoming')->put(
        'first-wins-products.csv',
        "SKU,NAME,PRICE\nA-1,First,1.00\nA-1,Second,2.00\n",
    );

    $firstWins = app(SyncManager::class)->run('first-wins-products')->runs[0];

    expect(Product::query()->where('sku', 'A-1')->value('name'))->toBe('First')
        ->and($firstWins->skipped)->toBe(1);

    Product::query()->delete();
    config()->set('data-sync.syncs', [FailDuplicatesProductsSync::class]);
    Storage::disk('incoming')->put(
        'fail-duplicates-products.csv',
        "SKU,NAME,PRICE\nA-1,First,1.00\nA-1,Second,2.00\n",
    );

    expect(fn () => app(SyncManager::class)->run('fail-duplicates-products'))
        ->toThrow(RuntimeException::class, 'duplicate identity');
    expect(Product::query()->count())->toBe(0);

    config()->set('data-sync.syncs', [CompositeProductsSync::class]);
    Storage::disk('incoming')->put(
        'composite-products.csv',
        "SKU,NAME,PRICE,CATEGORY\nA-1,Hardware,1.00,hardware\nA-1,Software,2.00,software\n",
    );

    $composite = app(SyncManager::class)->run('composite-products')->runs[0];

    expect(CompositeProduct::query()->where('sku', 'A-1')->count())->toBe(2)
        ->and($composite->created)->toBe(2);
});

it('skips existing rows in create-only mode and new rows in update-only mode', function (): void {
    Product::query()->create([
        'sku' => 'A-1',
        'name' => 'Existing',
        'price' => 100,
        'category' => 'general',
    ]);
    config()->set('data-sync.syncs', [CreateOnlyProductsSync::class]);
    Storage::disk('incoming')->put(
        'create-only-products.csv',
        "SKU,NAME,PRICE\nA-1,Changed,2.00\nB-2,Created,3.00\n",
    );

    $createOnly = app(SyncManager::class)->run('create-only-products')->runs[0];

    expect(Product::query()->where('sku', 'A-1')->value('name'))->toBe('Existing')
        ->and(Product::query()->where('sku', 'B-2')->exists())->toBeTrue()
        ->and($createOnly->created)->toBe(1)
        ->and($createOnly->skipped)->toBe(1);

    config()->set('data-sync.syncs', [UpdateOnlyProductsSync::class]);
    Storage::disk('incoming')->put(
        'update-only-products.csv',
        "SKU,NAME,PRICE\nA-1,Updated,4.00\nC-3,Must not exist,5.00\n",
    );

    $updateOnly = app(SyncManager::class)->run('update-only-products')->runs[0];

    expect(Product::query()->where('sku', 'A-1')->value('name'))->toBe('Updated')
        ->and(Product::query()->where('sku', 'C-3')->exists())->toBeFalse()
        ->and($updateOnly->updated)->toBe(1)
        ->and($updateOnly->skipped)->toBe(1);
});

it('runs prepare and withValidator after field transforms', function (): void {
    config()->set('data-sync.syncs', [PreparedProductsSync::class]);
    Storage::disk('incoming')->put(
        'prepared-products.csv',
        "SKU,NAME,PRICE\nA-1,\" Alpha \",1.00\n",
    );
    app(SyncManager::class)->run('prepared-products');

    expect(Product::query()->where('sku', 'A-1')->value('name'))->toBe('Prepared Alpha');

    config()->set('data-sync.syncs', [ValidatorProductsSync::class]);
    Storage::disk('incoming')->put(
        'validator-products.csv',
        "SKU,NAME,PRICE\nB-2,Forbidden,2.00\n",
    );
    $validated = app(SyncManager::class)->run('validator-products')->runs[0];

    expect($validated->failed)->toBe(1)
        ->and(Product::query()->where('sku', 'B-2')->exists())->toBeFalse()
        ->and(SyncRowFailure::query()->latest('id')->value('errors'))
        ->toBe(['name' => ['This product name is forbidden.']]);
});

it('rolls back the whole file when atomic and only the failing chunk otherwise', function (): void {
    config()->set('data-sync.syncs', [AtomicProductsSync::class]);
    Storage::disk('incoming')->put(
        'atomic-products.csv',
        "SKU,NAME,PRICE\nA-1,Committed first,1.00\nB-2,Explode,2.00\n",
    );

    expect(fn () => app(SyncManager::class)->run('atomic-products'))
        ->toThrow(RuntimeException::class, 'Atomic hook exploded.');
    expect(Product::query()->count())->toBe(0);

    config()->set('data-sync.syncs', [ChunkedProductsSync::class]);
    Storage::disk('incoming')->put(
        'chunked-products.csv',
        "SKU,NAME,PRICE\nA-1,Committed first,1.00\nB-2,Explode,2.00\n",
    );

    expect(fn () => app(SyncManager::class)->run('chunked-products'))
        ->toThrow(RuntimeException::class, 'Chunk hook exploded.');
    expect(Product::query()->pluck('sku')->all())->toBe(['A-1']);
});
