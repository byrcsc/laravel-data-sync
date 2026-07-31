<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Tests\Stubs\InvalidCaptureProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ReceiptTransfer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function createDoctorJobBatchesTable(): void
{
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
    });
}

it('fails when a required package table is missing', function (): void {
    $this->artisan('sync:doctor')
        ->expectsOutputToContain('job_batches')
        ->assertFailed();
});

it('reports the exact package needed by a missing Flysystem adapter', function (): void {
    createDoctorJobBatchesTable();
    config()->set('data-sync.connections.vendor', [
        'driver' => 'ftp',
        'host' => 'ftp.example.com',
    ]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('composer require league/flysystem-ftp')
        ->assertFailed();
});

it('fails when a configured connection cannot be reached', function (): void {
    createDoctorJobBatchesTable();
    config()->set('data-sync.connections.vendor', [
        'driver' => 'unsupported',
    ]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('data-sync.connections.vendor')
        ->assertFailed();
});

it('fails when a working file disk is not writable', function (): void {
    createDoctorJobBatchesTable();
    config()->set('data-sync.archive.disk', 'missing');

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('data-sync.archive.disk')
        ->assertFailed();
});

it('fails when a registered definition does not resolve cleanly', function (): void {
    createDoctorJobBatchesTable();
    config()->set('data-sync.syncs', [InvalidCaptureProductsSync::class]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('registered definition could not be resolved')
        ->assertFailed();
});

it('warns that local staging is unsafe for multi-server workers and strict mode fails', function (): void {
    createDoctorJobBatchesTable();

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('Multi-server workers require a shared staging disk')
        ->assertSuccessful();

    $this->artisan('sync:doctor', ['--strict' => true])
        ->expectsOutputToContain('Multi-server workers require a shared staging disk')
        ->assertFailed();
});

it('fails when the configured cache store cannot provide a lock', function (): void {
    createDoctorJobBatchesTable();
    config()->set('cache.default', 'missing');

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('lock store')
        ->assertFailed();
});

it('warns when matchOn columns do not have a matching unique index', function (): void {
    createDoctorJobBatchesTable();
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku');
        $table->string('name');
        $table->integer('price');
        $table->string('category')->nullable();
    });
    Storage::fake('incoming');
    config()->set('data-sync.syncs', [ProductsSync::class]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('matchOn [sku]')
        ->assertSuccessful();
});

it('warns about runs that have remained running for more than an hour', function (): void {
    createDoctorJobBatchesTable();
    $file = SyncFile::query()->create([
        'definition' => 'products',
        'checksum' => str_repeat('a', 64),
        'path' => 'products.csv',
        'filename' => 'products.csv',
        'size' => 10,
        'mtime' => now()->timestamp,
        'status' => SyncFileStatus::Processing,
        'first_seen_at' => now()->subHours(2),
    ]);
    $run = SyncRun::query()->create([
        'sync_file_id' => $file->id,
        'definition' => 'products',
        'status' => RunStatus::Running,
        'started_at' => now()->subHours(2),
    ]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain("Run [{$run->ulid}] has been running")
        ->assertSuccessful();
});

it('fails when a registered definition source cannot be reached', function (): void {
    createDoctorJobBatchesTable();
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price');
        $table->string('category')->nullable();
    });
    config()->set('data-sync.syncs', [ProductsSync::class]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('source for definition [products]')
        ->assertFailed();
});

it('reports every independent preflight failure in one run', function (): void {
    config()->set('data-sync.archive.disk', 'missing');
    config()->set('cache.default', 'missing');

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('job_batches')
        ->expectsOutputToContain('data-sync.archive.disk')
        ->expectsOutputToContain('lock store')
        ->assertFailed();
});

it('checks transfer destination disks', function (): void {
    createDoctorJobBatchesTable();
    Storage::fake('incoming');
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('destination for transfer [receipt-transfer]')
        ->assertFailed();
});
