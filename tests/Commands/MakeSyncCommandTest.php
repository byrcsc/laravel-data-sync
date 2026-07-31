<?php

declare(strict_types=1);

use App\Syncs\ProductsSync;
use App\Syncs\ReceiptsTransfer;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->generatedAppPath = sys_get_temp_dir().'/laravel-data-sync-'.Str::ulid();
    app()->useAppPath($this->generatedAppPath);
});

afterEach(function (): void {
    app(Filesystem::class)->deleteDirectory($this->generatedAppPath);
});

it('generates a ready-to-edit sync definition', function (): void {
    $this->artisan('make:sync', ['name' => 'Products'])
        ->assertSuccessful();

    $path = app_path('Syncs/ProductsSync.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('final class ProductsSync extends SyncDefinition')
        ->and($contents)->toContain('public function format(): ReaderContract')
        ->and($contents)->toContain('public function matchOn(): array');

    require_once $path;
    expect(is_subclass_of(ProductsSync::class, SyncDefinition::class))->toBeTrue();
});

it('generates a ready-to-edit transfer definition', function (): void {
    $this->artisan('make:sync', [
        'name' => 'Receipts',
        '--transfer' => true,
    ])->assertSuccessful();

    $path = app_path('Syncs/ReceiptsTransfer.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('final class ReceiptsTransfer extends TransferDefinition')
        ->and($contents)->toContain('public function matchFilename(): string')
        ->and($contents)->toContain('public function destination(): Destination');

    require_once $path;
    expect(is_subclass_of(ReceiptsTransfer::class, TransferDefinition::class))->toBeTrue();
});
