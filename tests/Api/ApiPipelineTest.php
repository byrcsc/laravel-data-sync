<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\FinalizeRun;
use ByRcsc\LaravelDataSync\Actions\ProcessFileInline;
use ByRcsc\LaravelDataSync\Actions\PruneHistory;
use ByRcsc\LaravelDataSync\Actions\RestoreRunFile;
use ByRcsc\LaravelDataSync\Actions\StageFile;
use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncStarted;
use ByRcsc\LaravelDataSync\Jobs\ProcessFileJob;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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

    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.syncs', [ApiProductsSync::class]);

    // Http::fake() merges stubs rather than replacing them, so the feed is
    // registered once and each test moves it on.
    $this->feed = ['data' => [], 'meta' => ['next_page_url' => null, 'max_updated_at' => null]];
    Http::fake(fn () => Http::response($this->feed));
});

/**
 * @param  list<array<string, string>>  $records
 */
function fakeFeed(array $records, ?string $watermark = null): void
{
    test()->feed = [
        'data' => $records,
        'meta' => ['next_page_url' => null, 'max_updated_at' => $watermark],
    ];
}

function twoProducts(): array
{
    return [
        ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00'],
        ['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '2.00'],
    ];
}

it('runs an api definition inline end to end and advances the watermark', function (): void {
    Event::fake([SyncStarted::class, SyncCompleted::class]);
    fakeFeed(twoProducts(), watermark: '2026-08-13T09:00:00Z');

    $report = app(SyncManager::class)->run('api-products');
    $run = $report->runs[0]->run;

    expect($report->discovered)->toBe(1)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(2)
        ->and($run->rows_created)->toBe(2)
        ->and(Product::query()->pluck('name', 'sku')->all())->toBe(['A-1' => 'Alpha', 'B-2' => 'Beta'])
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('2026-08-13T09:00:00Z');

    Event::assertDispatched(SyncStarted::class);
    Event::assertDispatched(SyncCompleted::class);
});

it('runs an api definition through the command', function (): void {
    fakeFeed(twoProducts(), watermark: 'from-command');

    $this->artisan('sync:run', ['name' => 'api-products', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(2)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('from-command');
});

it('holds the watermark when a run completes with row errors', function (): void {
    fakeFeed([
        ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00'],
        ['SKU' => 'B-2', 'PRICE' => '2.00'],
    ], watermark: 'not-committed');

    $run = app(SyncManager::class)->run('api-products')->runs[0]->run;

    expect($run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->rows_failed)->toBe(1)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBeNull();

    // Holding the cursor is only half of it: the ledger has to let the same
    // window through again, or the refetch is thrown away.
    $second = app(SyncManager::class)->run('api-products');

    expect($second->discovered)->toBe(1)
        ->and($second->skipped)->toBe(0)
        ->and($second->runs[0]->run->status)->toBe(RunStatus::CompletedWithErrors);
});

it('holds the watermark when a run fails outright', function (): void {
    fakeFeed(twoProducts(), watermark: 'not-committed');
    app(SyncManager::class)->run('api-products');
    Schema::drop('products');

    // A second window has to be fetched, so the payload differs and the ledger
    // does not skip it.
    fakeFeed([['SKU' => 'C-3', 'NAME' => 'Gamma', 'PRICE' => '3.00']], watermark: 'also-not-committed');

    expect(fn () => app(SyncManager::class)->run('api-products'))->toThrow(QueryException::class)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('not-committed');

    expect(SyncRun::query()->where('status', RunStatus::Failed->value)->count())->toBe(1);
});

it('advances the watermark a quiet feed reports without changing', function (): void {
    fakeFeed(twoProducts(), watermark: 'first-window');
    app(SyncManager::class)->run('api-products');

    fakeFeed([], watermark: 'first-window');
    $run = app(SyncManager::class)->run('api-products')->runs[0]->run;

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(0)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('first-window');
});

it('fetches during a dry run without writing, marking, or advancing', function (): void {
    fakeFeed(twoProducts(), watermark: 'not-committed');

    $run = app(SyncManager::class)->run('api-products', dryRun: true)->runs[0]->run;

    expect($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(2)
        ->and(Product::query()->count())->toBe(0)
        ->and(SyncFile::query()->firstOrFail()->status)->toBe(SyncFileStatus::Pending)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBeNull();

    Http::assertSentCount(1);
});

it('queues an api run through the same file job', function (): void {
    Queue::fake();
    fakeFeed(twoProducts(), watermark: 'queued');

    $report = app(SyncManager::class)->queue('api-products');

    expect($report->discovered)->toBe(1)
        ->and($report->runs[0]->run->status)->toBe(RunStatus::Pending);

    Queue::assertPushed(ProcessFileJob::class);
});

it('archives the snapshot on completion and restores it for a retry', function (): void {
    fakeFeed(twoProducts(), watermark: 'archived');

    $run = app(SyncManager::class)->run('api-products')->runs[0]->run;

    expect($run->archive_path)->not->toBeNull()
        ->and(Storage::disk('archive')->exists((string) $run->archive_path))->toBeTrue();

    Storage::disk('staging')->deleteDirectory('data-sync');
    Queue::fake();

    $this->artisan('sync:retry', ['run' => $run->ulid])->assertSuccessful();

    $file = $run->file()->firstOrFail();

    expect(Storage::disk('staging')->exists(StageFile::pathFor(
        'api-products',
        (string) $file->checksum,
        (string) $file->filename,
    )))->toBeTrue();

    Queue::assertPushed(ProcessFileJob::class);
});

it('does not rewind the cursor when an older snapshot is reprocessed', function (): void {
    fakeFeed([['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']], watermark: 'window-one');
    $first = app(SyncManager::class)->run('api-products')->runs[0]->run;

    fakeFeed([['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '2.00']], watermark: 'window-two');
    app(SyncManager::class)->run('api-products');

    expect(app(TrackWatermark::class)->current('api-products'))->toBe('window-two');

    // Replay the first snapshot the way sync:retry does.
    [$file] = app(RestoreRunFile::class)((string) $first->ulid);
    app(ProcessFileInline::class)($definition = app(SyncManager::class)->definition('api-products'), $file);

    expect(app(TrackWatermark::class)->current('api-products'))->toBe('window-two')
        ->and(Product::query()->count())->toBe(2);
});

it('leaves the cursor alone when a run is cancelled', function (): void {
    Queue::fake();
    fakeFeed(twoProducts(), watermark: 'not-committed');
    $report = app(SyncManager::class)->queue('api-products');
    $run = $report->runs[0]->run;
    $file = $run->file()->firstOrFail();

    app(FinalizeRun::class)->cancel(
        app(SyncManager::class)->definition('api-products'),
        $file,
        $run,
    );

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and(app(TrackWatermark::class)->current('api-products'))->toBeNull();
});

it('sweeps archived snapshots on the configured retention', function (): void {
    fakeFeed(twoProducts(), watermark: 'pruned');
    $run = app(SyncManager::class)->run('api-products')->runs[0]->run;
    config()->set('data-sync.retention.archive_days', 0);

    app(PruneHistory::class)();

    expect(Storage::disk('archive')->exists((string) $run->archive_path))->toBeFalse()
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('pruned');
});

it('applies the chunk size override to an api definition', function (): void {
    config()->set('data-sync.overrides.api-products.chunk_size', 1);
    fakeFeed(twoProducts(), watermark: 'chunked');

    $run = app(SyncManager::class)->run('api-products')->runs[0]->run;

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(2)
        ->and(Product::query()->count())->toBe(2);
});
