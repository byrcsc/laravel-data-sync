<?php

declare(strict_types=1);

return [
    'syncs' => [],

    'connections' => [],

    'staging' => [
        'disk' => 'local',
        'path' => 'data-sync/staging',
    ],

    'archive' => [
        'disk' => 'local',
        'path' => 'data-sync/archive',
    ],

    'failed' => [
        'disk' => null,
        'path' => 'data-sync/failed',
    ],

    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

    'register_schedules' => true,

    'overrides' => [],

    'retention' => [
        'runs_days' => 90,
        'row_failures_days' => 30,
        'archive_days' => 30,
        'failed_files_days' => 14,
    ],

    'store_failed_payloads' => true,

    'tables' => [
        'files' => 'sync_files',
        'runs' => 'sync_runs',
        'row_failures' => 'sync_row_failures',
    ],
];
