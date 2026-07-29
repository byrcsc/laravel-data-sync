<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\DiscoverFiles;
use ByRcsc\LaravelDataSync\Actions\StageFile;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Tests\Stubs\DirectoryProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\YoungProductsSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('incoming');
    Storage::fake('staging');
    config()->set('data-sync.staging.disk', 'staging');
});

it('stages and records a file with its SHA-256 checksum', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");

    $result = app(DiscoverFiles::class)(new ProductsSync);
    $file = $result->files[0];

    expect($result->files)->toHaveCount(1)
        ->and($file->checksum)->toBe(hash('sha256', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n"))
        ->and($file->status)->toBe(SyncFileStatus::Pending)
        ->and(Storage::disk('staging')->exists(StageFile::pathFor(
            'products',
            (string) $file->checksum,
            'products.csv',
        )))->toBeTrue();
});

it('uses successful stat metadata as a cheap skip', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    $first = app(DiscoverFiles::class)(new ProductsSync);
    $first->files[0]->forceFill(['status' => SyncFileStatus::Completed])->save();

    $second = app(DiscoverFiles::class)(new ProductsSync);

    expect($second->files)->toBe([])
        ->and($second->skipped)->toBe(1)
        ->and(SyncFile::query()->count())->toBe(1);
});

it('treats checksum as authoritative across renamed and changed files', function (): void {
    $contents = "SKU,NAME,PRICE\nA-1,Alpha,1.00\n";
    Storage::disk('incoming')->put('inbox/first.csv', $contents);
    $first = app(DiscoverFiles::class)(new DirectoryProductsSync);
    $first->files[0]->forceFill(['status' => SyncFileStatus::Completed])->save();

    Storage::disk('incoming')->put('inbox/renamed.csv', $contents);
    $renamed = app(DiscoverFiles::class)(new DirectoryProductsSync);

    expect($renamed->files)->toBe([])
        ->and($renamed->skipped)->toBe(2);

    Storage::disk('incoming')->put('inbox/first.csv', "SKU,NAME,PRICE\nA-1,Changed,20.00\n");
    $changed = app(DiscoverFiles::class)(new DirectoryProductsSync);

    expect($changed->files)->toHaveCount(1)
        ->and($changed->files[0]->checksum)->not->toBe($first->files[0]->checksum);
});

it('re-eligibles a failed checksum and force reprocesses a completed checksum', function (): void {
    Storage::disk('incoming')->put('products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");
    $first = app(DiscoverFiles::class)(new ProductsSync);
    $first->files[0]->forceFill(['status' => SyncFileStatus::Failed])->save();

    $retry = app(DiscoverFiles::class)(new ProductsSync);

    expect($retry->files)->toHaveCount(1)
        ->and($retry->files[0]->is($first->files[0]))->toBeTrue()
        ->and($retry->files[0]->status)->toBe(SyncFileStatus::Pending);

    $retry->files[0]->forceFill(['status' => SyncFileStatus::Completed])->save();
    $forced = app(DiscoverFiles::class)(new ProductsSync, force: true);

    expect($forced->files)->toHaveCount(1)
        ->and($forced->files[0]->is($first->files[0]))->toBeTrue();
});

it('defers young files and returns a no-op when discovery overlaps', function (): void {
    Storage::disk('incoming')->put('young-products.csv', "SKU,NAME,PRICE\nA-1,Alpha,1.00\n");

    expect(app(DiscoverFiles::class)(new YoungProductsSync)->deferred)->toBe(1);

    $lock = Cache::lock('data-sync:discover:products', 300);
    $lock->get();

    try {
        expect(app(DiscoverFiles::class)(new ProductsSync)->locked)->toBeTrue();
    } finally {
        $lock->release();
    }
});
