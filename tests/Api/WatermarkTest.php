<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\PruneHistory;
use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\Models\SyncCursor;
use Illuminate\Support\Facades\Schema;

it('reports no watermark before the first fetch', function (): void {
    expect(app(TrackWatermark::class)->current('api-products'))->toBeNull();
});

it('round-trips scalar and structured watermark values', function (): void {
    $track = app(TrackWatermark::class);

    $track->advance('api-products', '2026-08-13T09:00:00Z');
    expect($track->current('api-products'))->toBe('2026-08-13T09:00:00Z');

    $track->advance('api-products', ['page' => 4, 'since' => '2026-08-13']);
    expect($track->current('api-products'))->toBe(['page' => 4, 'since' => '2026-08-13']);
});

it('keeps one row per definition however often it advances', function (): void {
    $track = app(TrackWatermark::class);

    $track->advance('api-products', 'first');
    $track->advance('api-products', 'second');
    $track->advance('api-orders', 'other');

    expect(SyncCursor::query()->where('definition', 'api-products')->count())->toBe(1)
        ->and($track->current('api-products'))->toBe('second')
        ->and($track->current('api-orders'))->toBe('other');
});

it('records an advance time whenever the value moves', function (): void {
    app(TrackWatermark::class)->advance('api-products', 'first');

    expect(SyncCursor::query()->where('definition', 'api-products')->firstOrFail()->advanced_at)
        ->not->toBeNull();
});

it('never prunes cursors', function (): void {
    app(TrackWatermark::class)->advance('api-products', 'kept');
    config()->set('data-sync.retention', [
        'runs_days' => 0,
        'row_failures_days' => 0,
        'archive_days' => 0,
        'failed_files_days' => 0,
    ]);

    app(PruneHistory::class)();

    expect(app(TrackWatermark::class)->current('api-products'))->toBe('kept');
});

it('respects a configured cursors table name end to end', function (): void {
    $this->dropDataSyncTables();
    config()->set('data-sync.tables.cursors', 'import_cursors');
    $this->runDataSyncMigration();

    app(TrackWatermark::class)->advance('api-products', 'renamed');

    expect(Schema::hasTable('import_cursors'))->toBeTrue()
        ->and(Schema::hasTable('sync_cursors'))->toBeFalse()
        ->and(app(TrackWatermark::class)->current('api-products'))->toBe('renamed');
});
