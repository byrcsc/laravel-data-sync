<?php

declare(strict_types=1);

use App\Syncs\BookingsSync;
use App\Syncs\ProductsSync;
use App\Syncs\ReceiptsTransfer;
use ByRcsc\LaravelDataSync\Definitions\ApiDefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
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

it('generates a ready-to-edit api sync definition', function (): void {
    $this->artisan('make:sync', [
        'name' => 'Bookings',
        '--api' => true,
    ])->assertSuccessful();

    $path = app_path('Syncs/BookingsSync.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('final class BookingsSync extends ApiSyncDefinition')
        ->and($contents)->toContain('public function endpoint(): string')
        ->and($contents)->toContain('public function request(PendingRequest $http, mixed $watermark)')
        ->and($contents)->toContain('public function nextPage(Response $response): ?string')
        ->and($contents)->toContain('public function watermarkFrom(Response $response, iterable $records)')
        ->and($contents)->toContain('public function schedule(Event $event): void')
        // The sealed members are the package's, so the stub must not offer them.
        ->and($contents)->not->toContain('public function format(')
        ->and($contents)->not->toContain('public function source(');

    require_once $path;

    // The endpoint and knobs the stub ships with have to survive boot as they
    // are; only the model and the mapping are left for the reader to fill in.
    expect(is_subclass_of(BookingsSync::class, ApiSyncDefinition::class))->toBeTrue()
        ->and(fn () => app(ApiDefinitionValidator::class)->validate(new BookingsSync))
        ->not->toThrow(InvalidDefinitionException::class);
});

it('refuses to generate a definition that is both a transfer and an api sync', function (): void {
    $this->artisan('make:sync', [
        'name' => 'Confused',
        '--transfer' => true,
        '--api' => true,
    ])->assertFailed();

    expect(file_exists(app_path('Syncs/ConfusedTransfer.php')))->toBeFalse();
});
