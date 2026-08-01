<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Facades\DataSync;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\QueuedProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Every surface the README tells a host application to reach for, driven the
 * way the README drives it. A definition class, the facade, the reports, and
 * the Artisan commands are the whole consumable API.
 */
beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
        $table->json('meta')->nullable();
    });

    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.syncs', [ProductsSync::class]);
});

it('registers, lists, and resolves definitions through the facade', function (): void {
    expect(DataSync::registered())->toHaveKey('products')
        ->and(DataSync::registered()['products'])->toBeInstanceOf(Definition::class)
        ->and(DataSync::enabled())->toHaveKey('products')
        ->and(DataSync::definition('products')->name())->toBe('products');

    config()->set('data-sync.overrides.products.enabled', false);

    expect(DataSync::registered())->toHaveKey('products')
        ->and(DataSync::enabled())->not->toHaveKey('products');
});

it('runs a sync in process and reports what it wrote', function (): void {
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,12.50\nB-2,Beta,9.99\n",
    );

    $report = DataSync::run('products');

    expect($report)->toBeInstanceOf(RunReport::class)
        ->and($report->definition)->toBe('products')
        ->and($report->discovered)->toBe(1)
        ->and($report->runs)->toHaveCount(1);

    $sync = $report->runs[0];

    expect($sync->status)->toBe(RunStatus::Completed)
        ->and($sync->writePath)->toBe(WritePath::Bulk)
        ->and($sync->total)->toBe(2)
        ->and($sync->created)->toBe(2)
        ->and($sync->failed)->toBe(0)
        ->and(Product::query()->pluck('sku')->all())->toBe(['A-1', 'B-2'])
        ->and(Product::query()->where('sku', 'A-1')->sole()->price)->toBe(1250);
});

it('queues a sync through the batch path the README documents', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [QueuedProductsSync::class]);
    Storage::disk('incoming')->put(
        'queued-products.csv',
        "SKU,NAME,PRICE\nQ-1,Queued,1.00\n",
    );

    $report = DataSync::queue('queued-products');

    expect($report->runs)->toHaveCount(1)
        ->and($report->runs[0]->status)->toBe(RunStatus::Pending);

    Queue::assertPushed(ProcessFileJob::class);
});

it('previews with a dry run and forces a completed file to run again', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");

    DataSync::run('products', dryRun: true);

    expect(Product::query()->count())->toBe(0);

    DataSync::run('products');

    expect(Product::query()->count())->toBe(1)
        ->and(DataSync::run('products')->runs)->toBe([]);

    DataSync::run('products', force: true);

    // A dry run is recorded too: preview, real run, forced run.
    expect(SyncRun::query()->count())->toBe(3)
        ->and(SyncRun::query()->where('dry_run', true)->count())->toBe(1)
        ->and(SyncFile::query()->count())->toBe(1);
});

it('exposes the ledger, run history, and failures as ordinary Eloquent models', function (): void {
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\nB-2,,2.00\n",
    );

    DataSync::run('products');

    $file = SyncFile::query()->sole();
    $run = SyncRun::query()->sole();

    expect($file->runs()->count())->toBe(1)
        ->and($run->file?->is($file))->toBeTrue()
        ->and($run->failures()->count())->toBe(1)
        ->and($run->failures()->sole()->run?->is($run))->toBeTrue();
});

it('ships the operator commands the README lists', function (): void {
    // sync:doctor fails without the framework's batch table.
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
    });

    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");

    $this->artisan('sync:run', ['name' => 'products', '--now' => true])->assertSuccessful();
    $this->artisan('sync:status')->assertSuccessful();
    $this->artisan('sync:status', ['--failures' => true])->assertSuccessful();
    $this->artisan('sync:prune')->assertSuccessful();
    $this->artisan('sync:doctor')->assertSuccessful();
});

it('scaffolds a definition that the manager can immediately register', function (): void {
    $this->artisan('make:sync', ['name' => 'OrdersSync'])->assertSuccessful();

    $path = app_path('Syncs/OrdersSync.php');

    expect(file_exists($path))->toBeTrue()
        ->and((string) file_get_contents($path))->toContain('extends SyncDefinition');

    @unlink($path);
});

it('resolves the manager from the container and the facade alike', function (): void {
    expect(app(SyncManager::class))->toBeInstanceOf(SyncManager::class)
        ->and(DataSync::getFacadeRoot())->toBeInstanceOf(SyncManager::class);
});
