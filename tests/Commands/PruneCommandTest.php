<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-30 12:00:00');
    Storage::fake('archive');
    Storage::fake('failed');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.failed.disk', 'failed');
    config()->set('data-sync.retention', [
        'runs_days' => 30,
        'row_failures_days' => 10,
        'archive_days' => 20,
        'failed_files_days' => 5,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('prunes all retention categories without touching the ledger or running work', function (): void {
    $file = SyncFile::query()->create([
        'definition' => 'products',
        'checksum' => str_repeat('a', 64),
        'path' => 'products.csv',
        'filename' => 'products.csv',
        'size' => 10,
        'mtime' => now()->timestamp,
        'status' => SyncFileStatus::Completed,
        'first_seen_at' => now()->subDays(100),
    ]);
    $old = SyncRun::query()->create([
        'sync_file_id' => $file->id,
        'definition' => 'products',
        'status' => RunStatus::Completed,
        'write_strategy' => WriteStrategy::Bulk,
        'finished_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);
    $recent = SyncRun::query()->create([
        'sync_file_id' => $file->id,
        'definition' => 'products',
        'status' => RunStatus::CompletedWithErrors,
        'write_strategy' => WriteStrategy::Bulk,
        'finished_at' => now()->subDay(),
    ]);
    $running = SyncRun::query()->create([
        'sync_file_id' => $file->id,
        'definition' => 'products',
        'status' => RunStatus::Running,
        'write_strategy' => WriteStrategy::Bulk,
        'archive_path' => 'data-sync/archive/products/protected.csv',
        'started_at' => now()->subDays(100),
        'created_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ]);
    SyncRowFailure::query()->create([
        'sync_run_id' => $recent->id,
        'row_number' => 1,
        'errors' => ['name' => ['Required.']],
        'created_at' => now()->subDays(11),
        'updated_at' => now()->subDays(11),
    ]);
    SyncRowFailure::query()->create([
        'sync_run_id' => $recent->id,
        'row_number' => 2,
        'errors' => ['name' => ['Required.']],
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    SyncRowFailure::query()->create([
        'sync_run_id' => $running->id,
        'row_number' => 3,
        'errors' => ['name' => ['Required.']],
        'created_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ]);
    Storage::disk('archive')->put('data-sync/archive/products/expired.csv', 'old');
    Storage::disk('archive')->put('data-sync/archive/products/current.csv', 'new');
    Storage::disk('archive')->put('data-sync/archive/products/protected.csv', 'running');
    Storage::disk('failed')->put('data-sync/failed/products/expired.csv', 'old');
    Storage::disk('failed')->put('data-sync/failed/products/current.csv', 'new');
    touch(Storage::disk('archive')->path('data-sync/archive/products/expired.csv'), now()->subDays(21)->timestamp);
    touch(Storage::disk('archive')->path('data-sync/archive/products/protected.csv'), now()->subDays(100)->timestamp);
    touch(Storage::disk('failed')->path('data-sync/failed/products/expired.csv'), now()->subDays(6)->timestamp);

    $this->artisan('sync:prune')
        ->expectsOutputToContain('1 runs')
        ->assertSuccessful();

    expect(SyncFile::query()->count())->toBe(1)
        ->and(SyncRun::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(SyncRun::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(SyncRun::query()->whereKey($running->id)->exists())->toBeTrue()
        ->and(SyncRowFailure::query()->where('row_number', 1)->exists())->toBeFalse()
        ->and(SyncRowFailure::query()->where('row_number', 2)->exists())->toBeTrue()
        ->and(SyncRowFailure::query()->where('row_number', 3)->exists())->toBeTrue()
        ->and(Storage::disk('archive')->exists('data-sync/archive/products/expired.csv'))->toBeFalse()
        ->and(Storage::disk('archive')->exists('data-sync/archive/products/current.csv'))->toBeTrue()
        ->and(Storage::disk('archive')->exists('data-sync/archive/products/protected.csv'))->toBeTrue()
        ->and(Storage::disk('failed')->exists('data-sync/failed/products/expired.csv'))->toBeFalse()
        ->and(Storage::disk('failed')->exists('data-sync/failed/products/current.csv'))->toBeTrue();
});

it('keeps every category forever when all retention values are null', function (): void {
    config()->set('data-sync.retention', [
        'runs_days' => null,
        'row_failures_days' => null,
        'archive_days' => null,
        'failed_files_days' => null,
    ]);
    $file = SyncFile::query()->create([
        'definition' => 'products',
        'checksum' => str_repeat('b', 64),
        'path' => 'products.csv',
        'filename' => 'products.csv',
        'size' => 10,
        'mtime' => now()->timestamp,
        'status' => SyncFileStatus::Completed,
        'first_seen_at' => now()->subDays(100),
    ]);
    SyncRun::query()->create([
        'sync_file_id' => $file->id,
        'definition' => 'products',
        'status' => RunStatus::Completed,
        'write_strategy' => WriteStrategy::Bulk,
        'finished_at' => now()->subDays(100),
        'created_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ]);
    Storage::disk('archive')->put('data-sync/archive/products/old.csv', 'old');
    touch(Storage::disk('archive')->path('data-sync/archive/products/old.csv'), now()->subDays(100)->timestamp);

    $this->artisan('sync:prune')->assertSuccessful();

    expect(SyncRun::query()->count())->toBe(1)
        ->and(Storage::disk('archive')->exists('data-sync/archive/products/old.csv'))->toBeTrue();
});

it('reports filesystem pruning errors with the config key to check', function (): void {
    config()->set('data-sync.retention', [
        'runs_days' => null,
        'row_failures_days' => null,
        'archive_days' => 1,
        'failed_files_days' => null,
    ]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')
        ->with('data-sync/archive')
        ->andThrow(new RuntimeException('Storage is unavailable.'));
    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')->with('archive')->andReturn($disk);
    app()->instance(FilesystemManager::class, $filesystems);

    $this->artisan('sync:prune')
        ->expectsOutputToContain('data-sync.archive.disk')
        ->assertFailed();
});
