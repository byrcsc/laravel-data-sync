<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Facades\DataSync;
use ByRcsc\LaravelDataSync\SyncManager;

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
        ]);
});
