<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
use ByRcsc\LaravelDataSync\Exceptions\DataSyncException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\TimestampDatabaseProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The rules every snapshot source shares, run once per source kind. The
 * database source uses a timestamp watermark with an overlap, so a repeated
 * read of unchanged rows produces the same non-empty snapshot an unchanged
 * API feed does.
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

    Storage::fake('staging');
    Storage::fake('archive');
    Storage::fake('failed');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.failed.disk', 'failed');
    config()->set('data-sync.syncs', [ApiProductsSync::class, TimestampDatabaseProductsSync::class]);
    app()->instance(TimestampDatabaseProductsSync::class, new TimestampDatabaseProductsSync(overlapSeconds: 3600));

    createSourceProducts();

    $this->window = 0;
    $this->feed = null;
    Http::fake(fn () => $this->feed === null
        ? Http::response('unauthorized', 401)
        : Http::response($this->feed));
});

/**
 * @param  list<array{SKU: string, NAME: string|null, PRICE: string}>  $products
 */
function serve(string $source, array $products): void
{
    if ($source === 'api-products') {
        test()->window++;
        test()->feed = [
            'data' => $products,
            'meta' => ['next_page_url' => null, 'max_updated_at' => 'window-'.test()->window],
        ];

        return;
    }

    seedSourceProducts(array_map(
        fn (array $product): array => [...$product, 'UPDATED_AT' => '2026-11-09 10:00:00'],
        $products,
    ));
}

function breakSource(string $source): void
{
    if ($source === 'api-products') {
        test()->feed = null;

        return;
    }

    Schema::connection('source')->drop('source_products');
}

function products(): array
{
    return [
        ['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00'],
        ['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '2.00'],
        ['SKU' => 'C-3', 'NAME' => 'Gamma', 'PRICE' => '3.00'],
    ];
}

dataset('snapshot sources', ['api-products', 'timestamp-products']);

it('records a failed fetch as a visible failed run', function (string $source): void {
    Event::fake([SyncFailed::class]);
    breakSource($source);

    expect(fn () => app(SyncManager::class)->run($source))->toThrow(DataSyncException::class, "[{$source}] could not");

    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->definition)->toBe($source)
        ->and($run->error_summary)->not->toBeNull()
        ->and(app(TrackWatermark::class)->current($source))->toBeNull();

    Event::assertDispatched(SyncFailed::class);
})->with('snapshot sources');

it('never skips an empty snapshot', function (string $source): void {
    serve($source, []);

    // Every empty snapshot has the same checksum. The ledger must not skip the
    // second one, or the source goes quiet without a trace.
    foreach ([1, 2] as $attempt) {
        $report = app(SyncManager::class)->run($source);

        expect($report->discovered)->toBe(1, "attempt {$attempt}")
            ->and($report->runs[0]->run->status)->toBe(RunStatus::Completed)
            ->and($report->runs[0]->run->rows_total)->toBe(0);
    }
})->with('snapshot sources');

it('skips an identical non-empty snapshot unless forced', function (string $source): void {
    serve($source, products());
    app(SyncManager::class)->run($source);

    serve($source, products());
    $repeat = app(SyncManager::class)->run($source);

    expect($repeat->discovered)->toBe(0)
        ->and($repeat->skipped)->toBe(1);

    $forced = app(SyncManager::class)->run($source, force: true);

    expect($forced->discovered)->toBe(1)
        ->and($forced->runs[0]->run->status)->toBe(RunStatus::Completed);
})->with('snapshot sources');

it('moves the watermark only when a run completes', function (string $source): void {
    $broken = products();
    $broken[1]['NAME'] = null;

    serve($source, $broken);
    $partial = app(SyncManager::class)->run($source)->runs[0]->run;

    expect($partial->status)->toBe(RunStatus::CompletedWithErrors)
        ->and(app(TrackWatermark::class)->current($source))->toBeNull();

    // Holding the watermark is only half of it: the ledger has to let the same
    // window through again, or the refetch is thrown away.
    serve($source, $broken);
    $again = app(SyncManager::class)->run($source);

    expect($again->discovered)->toBe(1)
        ->and($again->runs[0]->run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and(app(TrackWatermark::class)->current($source))->toBeNull();

    serve($source, products());
    $fixed = app(SyncManager::class)->run($source)->runs[0]->run;

    expect($fixed->status)->toBe(RunStatus::Completed)
        ->and(app(TrackWatermark::class)->current($source))->not->toBeNull();
})->with('snapshot sources');
