<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncStarted;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DryRunProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\HookedProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\MaxErrorsProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The guarantees the README states under "Important behavior", asserted against
 * the engine so the documentation cannot drift away from the behavior.
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

    DryRunProductsSync::$prepared = 0;
    DryRunProductsSync::$beforeSaved = 0;
    DryRunProductsSync::$afterSaved = 0;

    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.syncs', [ProductsSync::class]);
});

it('identifies a file by the checksum of its contents rather than its name', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    $checksum = SyncFile::query()->sole()->checksum;

    Storage::disk('incoming')->delete('products.csv');
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    $rewritten = app(SyncManager::class)->run('products');

    expect($rewritten->runs)->toBe([])
        ->and(SyncFile::query()->count())->toBe(1);

    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha revised,1.01\n");
    app(SyncManager::class)->run('products');

    expect(SyncFile::query()->count())->toBe(2)
        ->and(SyncFile::query()->where('checksum', $checksum)->count())->toBe(1);
});

it('skips a completed file whose path, size, and mtime are all unchanged', function (): void {
    // Discovery stats before it checksums, so file identity is only consulted
    // once the stat differs. Ordinary writes move the mtime, which is why this
    // is a fast path rather than a hole.
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    $file = SyncFile::query()->sole();

    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,9.99\n");
    $path = Storage::disk('incoming')->path('products.csv');
    touch($path, $file->mtime);
    clearstatcache(true, $path);

    $report = app(SyncManager::class)->run('products');

    expect($report->runs)->toBe([])
        ->and($report->skipped)->toBe(1)
        ->and(SyncFile::query()->count())->toBe(1);

    app(SyncManager::class)->run('products', force: true);

    expect(SyncFile::query()->count())->toBe(2);
});

it('blocks reprocessing only after a successful run', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    expect(SyncFile::query()->sole()->status)->toBe(SyncFileStatus::Completed);

    $second = app(SyncManager::class)->run('products');
    expect($second->runs)->toBe([]);

    SyncFile::query()->sole()->update(['status' => SyncFileStatus::Failed]);
    $third = app(SyncManager::class)->run('products');

    expect($third->runs)->not->toBe([]);
});

it('takes the eloquent write strategy only when the definition declares a save hook', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    expect(SyncRun::query()->sole()->write_strategy)->toBe(WriteStrategy::Bulk);

    config()->set('data-sync.syncs', [HookedProductsSync::class]);
    Storage::disk('incoming')->put('hooked-products.csv', "SKU,NAME,PRICE\nH-1,Hooked,2.00\n");
    app(SyncManager::class)->run('hooked-products');

    expect(SyncRun::query()->latest('id')->first()?->write_strategy)->toBe(WriteStrategy::Eloquent);
});

it('dispatches package events synchronously and never queues them', function (): void {
    foreach (classesIn('Events') as $event) {
        $class = 'ByRcsc\\LaravelDataSync\\Events\\'.$event;

        expect(is_a($class, ShouldQueue::class, true))
            ->toBeFalse("[{$event}] must not implement ShouldQueue; queue your listeners instead.");
    }

    // Real listeners rather than Event::fake(): the point is that they run
    // without a worker, which a fake would hide.
    $seen = [];
    app('events')->listen(SyncStarted::class, function (SyncStarted $event) use (&$seen): void {
        $seen[] = 'started:'.$event->run->id;
    });
    app('events')->listen(SyncCompleted::class, function (SyncCompleted $event) use (&$seen): void {
        $seen[] = 'completed:'.$event->run->id;
    });

    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    $run = SyncRun::query()->sole();

    expect($seen)->toBe(['started:'.$run->id, 'completed:'.$run->id]);
});

it('skips prepare during a dry run, so its counts are a preview', function (): void {
    config()->set('data-sync.syncs', [DryRunProductsSync::class]);
    Storage::disk('incoming')->put('dry-run-products.csv', "SKU,NAME,PRICE\nD-1,Delta,3.00\n");

    app(SyncManager::class)->run('dry-run-products', dryRun: true);

    expect(DryRunProductsSync::$prepared)->toBe(0)
        ->and(DryRunProductsSync::$beforeSaved)->toBe(0)
        ->and(DryRunProductsSync::$afterSaved)->toBe(0)
        ->and(Product::query()->count())->toBe(0);

    app(SyncManager::class)->run('dry-run-products');

    expect(DryRunProductsSync::$prepared)->toBeGreaterThan(0)
        ->and(Product::query()->count())->toBe(1);
});

it('records failed rows and keeps going, with maxErrors as the only circuit breaker', function (): void {
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\nB-2,,2.00\nC-3,Gamma,3.00\n",
    );

    app(SyncManager::class)->run('products');
    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->rows_failed)->toBe(1)
        ->and($run->rows_created)->toBe(2)
        ->and($run->failures()->count())->toBe(1);

    config()->set('data-sync.syncs', [MaxErrorsProductsSync::class]);
    Storage::disk('incoming')->put(
        'max-errors-products.csv',
        "SKU,NAME,PRICE\nA-1,Committed,1.00\nB-2,,2.00\nC-3,,3.00\nD-4,Delta,4.00\n",
    );

    expect(fn () => app(SyncManager::class)->run('max-errors-products'))
        ->toThrow(MaxErrorsExceededException::class);

    $stopped = SyncRun::query()->latest('id')->first();

    expect($stopped?->status)->not->toBe(RunStatus::Completed)
        ->and(Product::query()->where('sku', 'A-1')->exists())->toBeTrue()
        ->and(Product::query()->where('sku', 'D-4')->exists())->toBeFalse();
});

it('never prunes the file ledger', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    app(SyncManager::class)->run('products');

    // Runs are pruned on finished_at, not created_at.
    SyncRun::query()->update(['finished_at' => now()->subYears(5), 'created_at' => now()->subYears(5)]);
    SyncFile::query()->update(['created_at' => now()->subYears(5), 'updated_at' => now()->subYears(5)]);

    config()->set('data-sync.retention.runs_days', 1);
    config()->set('data-sync.retention.row_failures_days', 1);

    $this->artisan('sync:prune')->assertSuccessful();

    expect(SyncRun::query()->count())->toBe(0)
        ->and(SyncFile::query()->count())->toBe(1);
});
