<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Facades\DataSync;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Support\Facades\File;

it('resolves the manager as a singleton behind the facade', function (): void {
    expect(app(SyncManager::class))->toBeInstanceOf(SyncManager::class)
        ->and(app('data-sync'))->toBe(app(SyncManager::class))
        ->and(DataSync::getFacadeRoot())->toBe(app(SyncManager::class));
});

it('loads infrastructure-only defaults', function (): void {
    expect(config('data-sync.syncs'))->toBe([])
        ->and(config('data-sync.connections'))->toBe([])
        ->and(config('data-sync.staging'))->toBe([
            'disk' => 'local',
            'path' => 'data-sync/staging',
        ])
        ->and(config('data-sync.archive'))->toBe([
            'disk' => 'local',
            'path' => 'data-sync/archive',
        ])
        ->and(config('data-sync.tables'))->toBe([
            'files' => 'sync_files',
            'runs' => 'sync_runs',
            'row_failures' => 'sync_row_failures',
            'cursors' => 'sync_cursors',
        ]);
});

it('publishes the cursors migration alongside the original one', function (): void {
    $directory = database_path('migrations');
    File::ensureDirectoryExists($directory);
    $before = array_map('strval', File::files($directory));

    $this->artisan('vendor:publish', ['--tag' => 'data-sync-migrations'])->run();

    $published = array_values(array_diff(array_map('strval', File::files($directory)), $before));

    try {
        $names = implode("\n", array_map('basename', $published));

        expect($published)->toHaveCount(2)
            ->and($names)->toContain('create_data_sync_tables')
            ->and($names)->toContain('add_data_sync_cursors_table');
    } finally {
        File::delete($published);
    }
});
