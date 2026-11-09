<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
            'write_strategy',
            'dry_run',
            'batch_id',
            'rows_total',
            'rows_created',
            'rows_updated',
            'rows_skipped',
            'rows_failed',
            'unmapped_columns',
            'error_summary',
            'warnings',
            'archive_path',
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
        'watermarks' => 'import_watermarks',
    ]);

    $this->runDataSyncMigration();

    expect(Schema::hasTable('import_files'))->toBeTrue()
        ->and(Schema::hasTable('import_runs'))->toBeTrue()
        ->and(Schema::hasTable('import_row_failures'))->toBeTrue()
        ->and(Schema::hasTable('import_watermarks'))->toBeTrue();
});

it('keeps a 1.0 run history readable after the 2.0 upgrade', function (): void {
    $this->dropDataSyncTables();
    (require __DIR__.'/../database/migrations/create_data_sync_tables.php.stub')->up();

    // A 1.0 install stored the strategy under its old column name.
    Schema::table('sync_runs', function (Blueprint $table): void {
        $table->renameColumn('write_strategy', 'write_path');
    });
    $fileId = DB::table('sync_files')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'definition' => 'products',
        'checksum' => str_repeat('a', 64),
        'path' => 'products.csv',
        'filename' => 'products.csv',
        'size' => 1,
        'mtime' => 1,
        'status' => 'completed',
        'first_seen_at' => now(),
    ]);
    DB::table('sync_runs')->insert([
        'ulid' => (string) Str::ulid(),
        'sync_file_id' => $fileId,
        'definition' => 'products',
        'status' => 'completed',
        'write_path' => 'bulk',
    ]);

    (require __DIR__.'/../database/migrations/upgrade_data_sync_tables_to_v2.php.stub')->up();

    expect(DB::table('sync_runs')->value('write_strategy'))->toBe('bulk')
        ->and(Schema::hasColumn('sync_runs', 'write_path'))->toBeFalse();
});
