<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\FetchApiSnapshot;
use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
use ByRcsc\LaravelDataSync\Exceptions\ApiFetchException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductSyncDefinition;
use ByRcsc\LaravelDataSync\Tests\Stubs\UnreliableApiProductsSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('staging');
    Storage::fake('failed');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.failed.disk', 'failed');
});

/**
 * @param  list<array<string, string>>  $records
 * @return array<string, mixed>
 */
function apiPage(array $records, ?string $next = null, ?string $watermark = null): array
{
    return [
        'data' => $records,
        'meta' => ['next_page_url' => $next, 'max_updated_at' => $watermark],
    ];
}

function snapshotOf(SyncFile $file): string
{
    return Storage::disk('staging')->get(StagingArea::pathFor(
        'api-products',
        (string) $file->checksum,
        (string) $file->filename,
    ));
}

it('pages the endpoint into one NDJSON snapshot in the ledger', function (): void {
    // Keyed by URL rather than by call order, so a page link that loses its own
    // query string fails here instead of silently refetching page one.
    Http::fake([
        '*api/products*page=2*' => Http::response(apiPage(
            [['SKU' => 'B-2', 'NAME' => 'Beta', 'PRICE' => '2.00']],
            watermark: '2026-08-13T09:00:00Z',
        )),
        'middleware.test/api/products*' => Http::response(apiPage(
            [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
            next: 'https://middleware.test/api/products?page=2',
        )),
    ]);

    $result = app(FetchApiSnapshot::class)(new ApiProductsSync);
    $file = $result->files[0];

    expect($result->files)->toHaveCount(1)
        ->and($file->definition)->toBe('api-products')
        ->and($file->status)->toBe(SyncFileStatus::Pending)
        ->and($file->filename)->toEndWith('.ndjson')
        ->and($file->filename)->toStartWith('api-products-')
        ->and($file->pending_watermark)->toBe('2026-08-13T09:00:00Z')
        ->and(snapshotOf($file))->toBe(
            '{"SKU":"A-1","NAME":"Alpha","PRICE":"1.00"}'."\n".
            '{"SKU":"B-2","NAME":"Beta","PRICE":"2.00"}'."\n",
        );

    // The page link's parameters win; the definition's survive alongside them.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'page=2')
        && str_contains($request->url(), 'updated_since='));
});

it('keeps the last watermark a page reported when the final page is empty', function (): void {
    Http::fake([
        '*api/products*page=2*' => Http::response(apiPage([])),
        'middleware.test/api/products*' => Http::response(apiPage(
            [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
            next: 'https://middleware.test/api/products?page=2',
            watermark: '2026-08-13T09:00:00Z',
        )),
    ]);

    $file = app(FetchApiSnapshot::class)(new ApiProductsSync)->files[0];

    expect($file->pending_watermark)->toBe('2026-08-13T09:00:00Z');
});

it('stops a feed that pages back to a URL it already fetched', function (): void {
    Http::fake(['middleware.test/*' => Http::response(apiPage(
        [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
        next: 'https://middleware.test/api/products',
    ))]);

    expect(fn () => app(FetchApiSnapshot::class)(new ApiProductsSync))
        ->toThrow(ApiFetchException::class, 'nextPage()');
});

it('passes the current watermark into the request and records the new one', function (): void {
    app(TrackWatermark::class)->advance('api-products', '2026-08-01T00:00:00Z');
    Http::fake([
        'middleware.test/*' => Http::response(apiPage(
            [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
            watermark: '2026-08-13T09:00:00Z',
        )),
    ]);

    $file = app(FetchApiSnapshot::class)(new ApiProductsSync)->files[0];

    Http::assertSent(fn ($request): bool => str_contains(
        $request->url(),
        'updated_since=2026-08-01T00%3A00%3A00Z',
    ));

    // The fetch records the value; only a completed run advances the watermark.
    expect($file->pending_watermark)->toBe('2026-08-13T09:00:00Z')
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('2026-08-01T00:00:00Z');
});

it('records a quiet feed as an empty snapshot rather than nothing at all', function (): void {
    Http::fake(['middleware.test/*' => Http::response(apiPage([], watermark: 'still-here'))]);

    $result = app(FetchApiSnapshot::class)(new ApiProductsSync);

    expect($result->files)->toHaveCount(1)
        ->and(snapshotOf($result->files[0]))->toBe('')
        ->and($result->files[0]->pending_watermark)->toBe('still-here');
});

it('skips an identical payload through the ledger unless forced', function (): void {
    Http::fake(['middleware.test/*' => Http::response(apiPage(
        [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
    ))]);

    $first = app(FetchApiSnapshot::class)(new ApiProductsSync);
    $first->files[0]->forceFill(['status' => SyncFileStatus::Completed])->save();

    $second = app(FetchApiSnapshot::class)(new ApiProductsSync);

    expect($second->files)->toBe([])
        ->and($second->skipped)->toBe(1)
        ->and(SyncFile::query()->count())->toBe(1);

    $forced = app(FetchApiSnapshot::class)(new ApiProductsSync, force: true);

    expect($forced->files)->toHaveCount(1)
        ->and($forced->files[0]->is($first->files[0]))->toBeTrue();
});

it('still reads the current watermark when forced', function (): void {
    app(TrackWatermark::class)->advance('api-products', '2026-08-01T00:00:00Z');
    Http::fake(['middleware.test/*' => Http::response(apiPage([]))]);

    app(FetchApiSnapshot::class)(new ApiProductsSync, force: true);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'updated_since=2026-08-01'));
});

it('retries a failing endpoint and then fails the run visibly', function (): void {
    Event::fake([SyncFailed::class]);
    Http::fake(['middleware.test/*' => Http::response('upstream exploded', 500)]);

    expect(fn () => app(FetchApiSnapshot::class)(new UnreliableApiProductsSync))
        ->toThrow(ApiFetchException::class);

    $run = SyncRun::query()->firstOrFail();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_summary)->not->toBeNull()
        ->and($run->definition)->toBe('api-products')
        ->and(app(TrackWatermark::class)->current('api-products'))->toBeNull();

    Event::assertDispatched(SyncFailed::class);
    // 1 initial attempt + the definition's 3 retries.
    Http::assertSentCount(4);
});

it('keeps the bytes of a failed fetch in the failed area', function (): void {
    Http::fake(['middleware.test/*' => Http::response('nope', 503)]);

    expect(fn () => app(FetchApiSnapshot::class)(new UnreliableApiProductsSync))
        ->toThrow(ApiFetchException::class);

    $run = SyncRun::query()->firstOrFail();

    expect($run->archive_path)->not->toBeNull()
        ->and(Storage::disk('failed')->exists((string) $run->archive_path))->toBeTrue()
        ->and(SyncFile::query()->firstOrFail()->status)->toBe(SyncFileStatus::Failed);
});

it('preserves the pages that did arrive when a later page fails', function (): void {
    Http::fake([
        '*api/products*page=2*' => Http::response('gone', 503),
        'middleware.test/api/products*' => Http::response(apiPage(
            [['SKU' => 'A-1', 'NAME' => 'Alpha', 'PRICE' => '1.00']],
            next: 'https://middleware.test/api/products?page=2',
        )),
    ]);

    expect(fn () => app(FetchApiSnapshot::class)(new UnreliableApiProductsSync))
        ->toThrow(ApiFetchException::class);

    $run = SyncRun::query()->firstOrFail();

    expect(Storage::disk('failed')->get((string) $run->archive_path))
        ->toBe('{"SKU":"A-1","NAME":"Alpha","PRICE":"1.00"}'."\n");
});

it('spends a single attempt on a request the remote rejected', function (): void {
    Http::fake(['middleware.test/*' => Http::response('unauthorized', 401)]);

    expect(fn () => app(FetchApiSnapshot::class)(new UnreliableApiProductsSync))
        ->toThrow(ApiFetchException::class);

    // A 401 stays a 401 however often it is sent.
    Http::assertSentCount(1);
});

it('records an overlapping fetch as a locked no-op', function (): void {
    Http::fake(['middleware.test/*' => Http::response(apiPage([]))]);
    $lock = Cache::lock('data-sync:discover:api-products', 300);
    $lock->get();

    try {
        $result = app(FetchApiSnapshot::class)(new ApiProductsSync);

        expect($result->locked)->toBeTrue()
            ->and($result->files)->toBe([]);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('holds the fetch lock for as long as the definition asks', function (int $lockTimeout, bool $overlapStarts): void {
    $definition = new class($lockTimeout) extends ApiProductSyncDefinition
    {
        public function __construct(private readonly int $seconds) {}

        public function name(): string
        {
            return 'api-products';
        }

        public function lockTimeout(): int
        {
            return $this->seconds;
        }
    };
    $overlapped = null;

    // Five minutes into a slow fetch, a scheduled run tries to start another.
    Http::fake(function () use (&$overlapped) {
        test()->travel(301)->seconds();
        $overlapped = Cache::lock('data-sync:discover:api-products', 1)->get();

        return Http::response(apiPage([]));
    });

    app(FetchApiSnapshot::class)($definition);

    expect($overlapped)->toBe($overlapStarts);
})->with([
    'the default of 300 seconds' => [300, true],
    'a longer lock timeout' => [600, false],
]);
