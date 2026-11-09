<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncStarted;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DryRunProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
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

    DryRunProductsSync::$prepared = 0;
    DryRunProductsSync::$beforeSaved = 0;
    DryRunProductsSync::$afterSaved = 0;
    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
});

it('records counters, failures, summaries, and lifecycle events', function (): void {
    Event::fake([SyncStarted::class, SyncCompleted::class]);
    config()->set('data-sync.syncs', [ProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE,EXTRA\nA-1,Alpha,1.00,raw\nB-2,,2.00,raw\n",
    );

    app(SyncManager::class)->run('products');
    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->rows_total)->toBe(2)
        ->and($run->rows_created)->toBe(1)
        ->and($run->rows_failed)->toBe(1)
        ->and($run->unmapped_columns)->toBe(['EXTRA'])
        ->and($run->error_summary)->toContain('name')
        ->and(SyncRowFailure::query()->count())->toBe(1);
    Event::assertDispatched(SyncStarted::class);
    Event::assertDispatched(SyncCompleted::class, function (SyncCompleted $event): bool {
        return $event->run->status === RunStatus::CompletedWithErrors;
    });

    $this->artisan('sync:status', ['name' => 'products', '--failures' => true])
        ->expectsOutputToContain('completed_with_errors')
        ->expectsOutputToContain('bulk')
        ->expectsOutputToContain('row 3')
        ->assertSuccessful();
});

it('creates a new run for a forced processing attempt', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );

    app(SyncManager::class)->run('products');
    app(SyncManager::class)->run('products', force: true);

    expect(SyncFile::query()->count())->toBe(1)
        ->and(SyncRun::query()->count())->toBe(2)
        ->and(SyncRun::query()->pluck('status')->all())
        ->toBe([RunStatus::Completed, RunStatus::Completed]);
});

it('suppresses failed payloads globally while retaining validation errors', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    config()->set('data-sync.capture_failed_payloads', false);
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,,1.00\n",
    );

    app(SyncManager::class)->run('products');
    $failure = SyncRowFailure::query()->sole();

    expect($failure->payload)->toBeNull()
        ->and($failure->errors)->toHaveKey('name')
        ->and(SyncRowFailure::query()->count())->toBe(1);
});

it('dry-runs the full report without writes, hooks, archive, or source cleanup', function (): void {
    config()->set('data-sync.syncs', [DryRunProductsSync::class]);
    Storage::disk('incoming')->put(
        'dry-run-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );

    $this->artisan('sync:run', ['name' => 'dry-run-products', '--dry-run' => true])
        ->expectsOutputToContain('1 would create')
        ->assertSuccessful();
    $dryRun = SyncRun::query()->sole();

    expect($dryRun->dry_run)->toBeTrue()
        ->and($dryRun->status)->toBe(RunStatus::Completed)
        ->and(Product::query()->count())->toBe(0)
        ->and(DryRunProductsSync::$prepared)->toBe(0)
        ->and(DryRunProductsSync::$beforeSaved)->toBe(0)
        ->and(DryRunProductsSync::$afterSaved)->toBe(0)
        ->and(Storage::disk('archive')->allFiles())->toBe([])
        ->and(Storage::disk('staging')->allFiles())->toBe([])
        ->and(Storage::disk('incoming')->exists('dry-run-products.csv'))->toBeTrue()
        ->and(SyncFile::query()->sole()->status)->toBe(SyncFileStatus::Pending);

    $this->artisan('sync:run', ['name' => 'dry-run-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->where('sku', 'A-1')->exists())->toBeTrue()
        ->and(DryRunProductsSync::$prepared)->toBeGreaterThan(0)
        ->and(DryRunProductsSync::$beforeSaved)->toBe(1)
        ->and(DryRunProductsSync::$afterSaved)->toBe(1)
        ->and(Storage::disk('incoming')->exists('dry-run-products.csv'))->toBeFalse()
        ->and(Storage::disk('archive')->allFiles())->toHaveCount(1)
        ->and(SyncRun::query()->count())->toBe(2);
});
