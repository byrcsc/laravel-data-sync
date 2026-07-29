<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the file ledger, run history, and row failure tables', function (): void {
    expect(Schema::hasColumns('sync_files', [
        'id',
        'ulid',
        'definition',
        'checksum',
        'path',
        'filename',
        'size',
        'mtime',
        'status',
        'first_seen_at',
        'last_processed_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('sync_runs', [
            'id',
            'ulid',
            'sync_file_id',
            'definition',
            'status',
            'write_path',
            'dry_run',
            'batch_id',
            'rows_total',
            'rows_created',
            'rows_updated',
            'rows_skipped',
            'rows_failed',
            'unmapped_columns',
            'error_summary',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('sync_row_failures', [
            'id',
            'sync_run_id',
            'row_number',
            'payload',
            'errors',
            'exception',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('uses configured table names throughout the migration', function (): void {
    $this->dropDataSyncTables();

    config()->set('data-sync.tables', [
        'files' => 'import_files',
        'runs' => 'import_runs',
        'row_failures' => 'import_row_failures',
    ]);

    $this->runDataSyncMigration();

    expect(Schema::hasTable('import_files'))->toBeTrue()
        ->and(Schema::hasTable('import_runs'))->toBeTrue()
        ->and(Schema::hasTable('import_row_failures'))->toBeTrue();
});
