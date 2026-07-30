<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Jobs\TransferFileJob;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Tests\Stubs\DeleteSourceReceiptTransfer;
use ByRcsc\LaravelDataSync\Tests\Stubs\FailExistingReceiptTransfer;
use ByRcsc\LaravelDataSync\Tests\Stubs\FailUnmatchedReceiptTransfer;
use ByRcsc\LaravelDataSync\Tests\Stubs\Receipt;
use ByRcsc\LaravelDataSync\Tests\Stubs\ReceiptTransfer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::create('receipts', function (Blueprint $table): void {
        $table->id();
        $table->string('unit_no');
        $table->string('contract_no')->unique();
        $table->string('path');
        $table->char('checksum', 64);
        $table->unsignedBigInteger('size');
    });

    ReceiptTransfer::$transferred = null;
    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    Storage::fake('failed');
    Storage::fake('receipts');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.failed.disk', 'failed');
});

it('extracts typed filename segments, renders the destination, links a model, and fires the hook', function (): void {
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    $contents = 'receipt bytes';
    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', $contents);

    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->assertSuccessful();

    $run = SyncRun::query()->sole();
    $receipt = Receipt::query()->sole();
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->rows_total)->toBe(1)
        ->and($run->rows_created)->toBe(1)
        ->and(Storage::disk('receipts')->get('U1/2026-07-30/U1_C100_20260730.pdf'))->toBe($contents)
        ->and($receipt->unit_no)->toBe('U1')
        ->and($receipt->contract_no)->toBe('C100')
        ->and($receipt->path)->toBe('U1/2026-07-30/U1_C100_20260730.pdf')
        ->and($receipt->checksum)->toBe(hash('sha256', $contents))
        ->and(ReceiptTransfer::$transferred?->segments['date'])->toBeInstanceOf(DateTimeImmutable::class)
        ->and(ReceiptTransfer::$transferred?->destinationPath)->toBe($receipt->path)
        ->and(Storage::disk('archive')->exists($run->archive_path))->toBeTrue();

    $this->artisan('sync:status', ['name' => 'receipt-transfer'])
        ->expectsOutputToContain('receipt-transfer')
        ->expectsOutputToContain('transfer')
        ->assertSuccessful();
});

it('skips unmatched files by default and fails malformed or strict unmatched filenames', function (): void {
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/readme.txt', 'ignored');

    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->expectsOutputToContain('1 skipped')
        ->assertSuccessful();

    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Completed)
        ->and(SyncRun::query()->sole()->rows_skipped)->toBe(1)
        ->and(Storage::disk('receipts')->allFiles())->toBe([]);

    SyncRun::query()->delete();
    SyncFile::query()->delete();
    Storage::disk('incoming')->deleteDirectory('receipts');
    Storage::disk('incoming')->put('receipts/U1_C100_20261340.pdf', 'bad date');

    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->expectsOutputToContain('date')
        ->assertFailed();
    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed);

    SyncRun::query()->delete();
    SyncFile::query()->delete();
    Storage::disk('incoming')->deleteDirectory('receipts');
    config()->set('data-sync.syncs', [FailUnmatchedReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/readme.txt', 'strict');

    $this->artisan('sync:run', ['name' => 'fail-unmatched-receipt-transfer', '--now' => true])
        ->expectsOutputToContain('does not match')
        ->assertFailed();
    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed);
});

it('removes a destination write when post-write checksum verification fails', function (): void {
    app()->bind(ChecksumVerifier::class, fn (): ChecksumVerifier => new class implements ChecksumVerifier
    {
        public function for(Filesystem $disk, string $path): string
        {
            return str_repeat('0', 64);
        }
    });
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', 'receipt bytes');

    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->assertFailed();

    expect(Storage::disk('receipts')->exists('U1/2026-07-30/U1_C100_20260730.pdf'))->toBeFalse()
        ->and(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed);
});

it('skips identical destinations, overwrites changed files, and repoints the linked model', function (): void {
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', 'version one');
    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->assertSuccessful();

    $this->artisan('sync:run', [
        'name' => 'receipt-transfer',
        '--now' => true,
        '--force' => true,
    ])->assertSuccessful();
    $identical = SyncRun::query()->latest('id')->firstOrFail();
    expect($identical->rows_skipped)->toBe(1);

    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', 'version two changed');
    $this->artisan('sync:run', ['name' => 'receipt-transfer', '--now' => true])
        ->assertSuccessful();
    $changed = SyncRun::query()->latest('id')->firstOrFail();

    expect(Storage::disk('receipts')->get('U1/2026-07-30/U1_C100_20260730.pdf'))->toBe('version two changed')
        ->and(Receipt::query()->count())->toBe(1)
        ->and(Receipt::query()->sole()->checksum)->toBe($changed->file()->firstOrFail()->checksum);
});

it('fails on a changed existing destination when configured and queues transfer jobs', function (): void {
    config()->set('data-sync.syncs', [FailExistingReceiptTransfer::class]);
    Storage::disk('receipts')->put('U1/2026-07-30/U1_C100_20260730.pdf', 'existing');
    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', 'changed');

    $this->artisan('sync:run', ['name' => 'fail-existing-receipt-transfer', '--now' => true])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
    expect(Storage::disk('receipts')->get('U1/2026-07-30/U1_C100_20260730.pdf'))->toBe('existing');

    SyncRun::query()->delete();
    SyncFile::query()->delete();
    Storage::disk('incoming')->deleteDirectory('receipts');
    Storage::disk('receipts')->deleteDirectory('U1');
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/U2_C200_20260730.pdf', 'queued');
    Queue::fake();

    $this->artisan('sync:run', ['name' => 'receipt-transfer'])
        ->assertSuccessful();

    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Pending);
    Queue::assertPushed(TransferFileJob::class);
});

it('dry-runs a transfer without destination, model, hook, archive, or source effects', function (): void {
    config()->set('data-sync.syncs', [ReceiptTransfer::class]);
    Storage::disk('incoming')->put('receipts/U1_C100_20260730.pdf', 'dry receipt');

    $this->artisan('sync:run', [
        'name' => 'receipt-transfer',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('1 would create')
        ->assertSuccessful();

    $run = SyncRun::query()->sole();
    expect($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and(Receipt::query()->count())->toBe(0)
        ->and(ReceiptTransfer::$transferred)->toBeNull()
        ->and(Storage::disk('receipts')->allFiles())->toBe([])
        ->and(Storage::disk('archive')->allFiles())->toBe([])
        ->and(Storage::disk('incoming')->exists('receipts/U1_C100_20260730.pdf'))->toBeTrue();
});

it('applies source cleanup only after creating the verified archive', function (): void {
    config()->set('data-sync.syncs', [DeleteSourceReceiptTransfer::class]);
    $source = 'receipts/U1_C100_20260730.pdf';
    Storage::disk('incoming')->put($source, 'cleanup receipt');

    $this->artisan('sync:run', [
        'name' => 'delete-source-receipt-transfer',
        '--now' => true,
    ])->assertSuccessful();

    $run = SyncRun::query()->sole();
    expect(Storage::disk('archive')->exists($run->archive_path))->toBeTrue()
        ->and(Storage::disk('incoming')->exists($source))->toBeFalse();
});
