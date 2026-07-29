<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DeleteSourceProductsSync;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
        $table->json('meta')->nullable();
    });

    Storage::fake('incoming');
    Storage::fake('staging');
    Storage::fake('archive');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.syncs', [DeleteSourceProductsSync::class]);
});

it('verifies a collision-proof archive before deleting the source', function (): void {
    $contents = "SKU,NAME,PRICE\nA-1,Alpha,1.00\n";
    Storage::disk('incoming')->put('delete-source-products.csv', $contents);

    app(SyncManager::class)->run('delete-source-products');
    $run = SyncRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->archive_path)->toContain($run->ulid)
        ->and($run->archive_path)->toContain('delete-source-products.csv')
        ->and(Storage::disk('archive')->get($run->archive_path))->toBe($contents)
        ->and(Storage::disk('incoming')->exists('delete-source-products.csv'))->toBeFalse();
});

it('never deletes the source when archive verification fails', function (): void {
    app()->bind(ChecksumVerifier::class, fn (): ChecksumVerifier => new class implements ChecksumVerifier
    {
        public function for(Filesystem $disk, string $path): string
        {
            return str_repeat('0', 64);
        }
    });
    Storage::disk('incoming')->put(
        'delete-source-products.csv',
        "SKU,NAME,PRICE\nA-1,Alpha,1.00\n",
    );

    expect(fn () => app(SyncManager::class)->run('delete-source-products'))
        ->toThrow(RuntimeException::class, 'Archive checksum verification failed');

    expect(Storage::disk('incoming')->exists('delete-source-products.csv'))->toBeTrue()
        ->and(Storage::disk('archive')->allFiles())->toBe([])
        ->and(SyncRun::query()->sole()->status)->toBe(RunStatus::Failed);
});
