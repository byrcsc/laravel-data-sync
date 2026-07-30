<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Tests\Stubs\AtomicProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\RetryProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
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

    RetryProductsSync::$requireName = true;
    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
});

it('dispatches a new whole-file run from the archive under the same ledger file', function (): void {
    config()->set('data-sync.syncs', [AtomicProductsSync::class]);
    Storage::disk('incoming')->put(
        'atomic-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );
    $this->artisan('sync:run', ['name' => 'atomic-products', '--now' => true])
        ->assertSuccessful();
    $original = SyncRun::query()->sole();
    $fileId = $original->sync_file_id;
    Product::query()->delete();
    Storage::disk('incoming')->delete('atomic-products.csv');
    Storage::disk('staging')->deleteDirectory('data-sync');
    Queue::fake();

    $this->artisan('sync:retry', ['run' => $original->ulid])
        ->expectsOutputToContain('Retry run')
        ->assertSuccessful();

    $retry = SyncRun::query()->latest('id')->firstOrFail();
    expect($retry->getKey())->not->toBe($original->getKey())
        ->and($retry->sync_file_id)->toBe($fileId)
        ->and($retry->status)->toBe(RunStatus::Pending);

    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $job): bool {
        app()->call([$job, 'handle']);

        return true;
    });

    expect(Product::query()->where('sku', 'A-1')->value('name'))->toBe('Alpha')
        ->and($retry->fresh()->status)->toBe(RunStatus::Completed);
});

it('re-pipelines captured failed rows into a new idempotent run', function (): void {
    config()->set('data-sync.syncs', [RetryProductsSync::class]);
    Storage::disk('incoming')->put(
        'retry-products.csv',
        "SKU,NAME,PRICE\nA-1,,1.00\n",
    );
    $this->artisan('sync:run', ['name' => 'retry-products', '--now' => true])
        ->assertSuccessful();
    $original = SyncRun::query()->sole();
    Storage::disk('archive')->delete((string) $original->archive_path);
    Storage::disk('staging')->deleteDirectory('data-sync');
    RetryProductsSync::$requireName = false;

    $this->artisan('sync:retry', [
        'run' => $original->ulid,
        '--failed-rows' => true,
    ])->assertSuccessful();

    $retry = SyncRun::query()->latest('id')->firstOrFail();
    expect($retry->sync_file_id)->toBe($original->sync_file_id)
        ->and($retry->status)->toBe(RunStatus::Completed)
        ->and($retry->rows_total)->toBe(1)
        ->and($retry->rows_created)->toBe(1)
        ->and($retry->rows_failed)->toBe(0)
        ->and(Product::query()->where('sku', 'A-1')->count())->toBe(1);

    $this->artisan('sync:retry', [
        'run' => $original->ulid,
        '--failed-rows' => true,
    ])->assertSuccessful();

    expect(Product::query()->where('sku', 'A-1')->count())->toBe(1);
});

it('explains how to enable payload capture instead of crashing', function (): void {
    config()->set('data-sync.syncs', [RetryProductsSync::class]);
    config()->set('data-sync.store_failed_payloads', false);
    Storage::disk('incoming')->put(
        'retry-products.csv',
        "SKU,NAME,PRICE\nA-1,,1.00\n",
    );
    $this->artisan('sync:run', ['name' => 'retry-products', '--now' => true])
        ->assertSuccessful();
    $original = SyncRun::query()->sole();

    $this->artisan('sync:retry', [
        'run' => $original->ulid,
        '--failed-rows' => true,
    ])
        ->expectsOutputToContain('data-sync.store_failed_payloads')
        ->assertFailed();

    expect(SyncRun::query()->count())->toBe(1);
});

it('finds an archived run after its history row was pruned', function (): void {
    config()->set('data-sync.syncs', [AtomicProductsSync::class]);
    Storage::disk('incoming')->put(
        'atomic-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );
    $this->artisan('sync:run', ['name' => 'atomic-products', '--now' => true])
        ->assertSuccessful();
    $original = SyncRun::query()->sole();
    $originalUlid = $original->ulid;
    $file = SyncFile::query()->sole();
    $original->delete();
    Product::query()->delete();
    Storage::disk('staging')->deleteDirectory('data-sync');
    Queue::fake();

    $this->artisan('sync:retry', ['run' => $originalUlid])
        ->assertSuccessful();

    expect(SyncFile::query()->count())->toBe(1)
        ->and(SyncRun::query()->sole()->sync_file_id)->toBe($file->id);
    Queue::assertPushed(ProcessFileJob::class);
});
