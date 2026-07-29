<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DryRunProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
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

    DryRunProductsSync::$prepared = 0;
    DryRunProductsSync::$beforeSaved = 0;
    DryRunProductsSync::$afterSaved = 0;
    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
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
