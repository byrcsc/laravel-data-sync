<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Jobs\ProcessChunkJob;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\AtomicProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\MaxErrorsProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\QueuedProductsSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
});

it('dispatches a file job by default with per-sync queue options', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [QueuedProductsSync::class]);
    Storage::disk('incoming')->put(
        'queued-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );

    $this->artisan('sync:run', ['name' => 'queued-products'])
        ->assertSuccessful();

    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $job): bool {
        return $job->connection === 'database'
            && $job->queue === 'imports';
    });
    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Pending);
});

it('fails the header before dispatching chunk jobs', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [QueuedProductsSync::class]);
    Storage::disk('incoming')->put(
        'queued-products.csv',
        "SKU,PRICE\nA-1,1.00\n",
    );
    $job = null;

    app(SyncManager::class)->queue('queued-products');
    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });
    Bus::fake();

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class, 'Actual header: [SKU, PRICE]');
    Bus::assertNothingBatched();
    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed)
        ->and(Product::query()->count())->toBe(0);
});

it('plans configured offset ranges into a batch', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [QueuedProductsSync::class]);
    Storage::disk('incoming')->put(
        'queued-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\nB-2,Beta,2.00\n",
    );
    $job = null;

    app(SyncManager::class)->queue('queued-products');
    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });
    Bus::fake();
    app()->call([$job, 'handle']);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $jobs = collect($batch->jobs);

        return $jobs->count() === 2
            && $jobs->every(fn (object $job): bool => $job instanceof ProcessChunkJob)
            && $jobs->pluck('offset')->all() === [0, 1]
            && $jobs->pluck('limit')->all() === [1, 1];
    });
});

it('keeps atomic queued files inside the file job without a batch', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [AtomicProductsSync::class]);
    Storage::disk('incoming')->put(
        'atomic-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );
    $job = null;

    app(SyncManager::class)->queue('atomic-products');
    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });
    Bus::fake();
    app()->call([$job, 'handle']);

    Bus::assertNothingBatched();
    expect(Product::query()->where('sku', 'A-1')->exists())->toBeTrue()
        ->and(SyncRun::query()->sole()->status)->toBe(RunStatus::Completed);
});

it('processes queued chunks through a real synchronous batch, one job per overridden chunk', function (): void {
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
    config()->set('data-sync.syncs', [ProductsSync::class]);
    config()->set('data-sync.queue.connection', 'sync');
    config()->set('data-sync.overrides.products.chunk_size', 1);
    config()->set('queue.batching.database', 'testing');
    Storage::disk('incoming')->put(
        'products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\nB-2,Beta,2.00\n",
    );

    $this->artisan('sync:run', ['name' => 'products'])
        ->assertSuccessful();
    $run = SyncRun::query()->sole();

    expect(Product::query()->count())->toBe(2)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->batch_id)->not->toBeNull()
        ->and(DB::table('job_batches')->where('id', $run->batch_id)->value('total_jobs'))->toBe(2)
        ->and($run->rows_total)->toBe(2)
        ->and($run->rows_created)->toBe(2);
});

it('cancels after max errors while keeping committed chunks and failed bytes', function (): void {
    Queue::fake();
    config()->set('data-sync.syncs', [MaxErrorsProductsSync::class]);
    Storage::disk('incoming')->put(
        'max-errors-products.csv',
        "SKU,NAME,PRICE\nA-1,Committed,1.00\nB-2,,2.00\nC-3,Cancelled,3.00\n",
    );
    $fileJob = null;
    $pendingBatch = null;

    app(SyncManager::class)->queue('max-errors-products');
    Queue::assertPushed(ProcessFileJob::class, function (ProcessFileJob $queued) use (&$fileJob): bool {
        $fileJob = $queued;

        return true;
    });
    Bus::fake();
    app()->call([$fileJob, 'handle']);
    Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch): bool {
        $pendingBatch = $batch;

        return true;
    });
    $chunkJobs = $pendingBatch->jobs;
    [$first] = $chunkJobs[0]->withFakeBatch();
    [$second, $batch] = $chunkJobs[1]->withFakeBatch();
    [$third] = $chunkJobs[2]->withFakeBatch(cancelledAt: CarbonImmutable::now());
    app()->call([$first, 'handle']);
    $exception = null;

    try {
        app()->call([$second, 'handle']);
    } catch (MaxErrorsExceededException $thrown) {
        $exception = $thrown;
    }

    // The chunk that crossed the limit cancels the batch, and a chunk that runs
    // after cancellation writes nothing.
    expect($exception)->not->toBeNull()
        ->and($batch->cancelled())->toBeTrue();
    app()->call([$third, 'handle']);

    foreach ($pendingBatch->catchCallbacks() as $callback) {
        $callback($batch, $exception);
    }

    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->rows_total)->toBe(2)
        ->and($run->rows_created)->toBe(1)
        ->and($run->rows_failed)->toBe(1)
        ->and(Product::query()->pluck('sku')->all())->toBe(['A-1'])
        ->and(Storage::disk('staging')->exists($run->archive_path))->toBeTrue();
});
