<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\RegisterSchedules;
use ByRcsc\LaravelDataSync\Actions\TrackWatermark;
use ByRcsc\LaravelDataSync\DataSyncServiceProvider;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\DottedNameProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\TenantSourcesProvider;
use ByRcsc\LaravelDataSync\Tests\Stubs\UnavailableDefinitionProvider;
use ByRcsc\LaravelDataSync\Tests\Stubs\WrongItemDefinitionProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku');
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
        $table->json('meta')->nullable();
        $table->unique(['category', 'sku']);
    });
    Schema::create('tenant_sources', function (Blueprint $table): void {
        $table->string('account')->primary();
        $table->string('source_table');
    });

    Storage::fake('staging');
    Storage::fake('archive');
    Storage::fake('failed');
    config()->set('data-sync.staging.disk', 'staging');
    config()->set('data-sync.archive.disk', 'archive');
    config()->set('data-sync.failed.disk', 'failed');
    config()->set('data-sync.syncs', [TenantSourcesProvider::class]);
});

/**
 * Connects a source for an account, the way the host application would when
 * a customer adds one.
 *
 * @param  list<array{SKU: string, NAME: string}>  $rows
 */
function connectSource(string $account, array $rows): void
{
    $table = 'source_products_'.str_replace(['.', '-'], '_', $account);
    $connection = "tenant_{$account}";

    // The provider registers the same config. On sqlite every connection
    // name is its own in-memory database, so the table is created on the
    // account's connection rather than on `source`.
    config()->set("database.connections.{$connection}", config('database.connections.source'));
    $schema = Schema::connection($connection);
    $schema->dropIfExists($table);
    $schema->create($table, function (Blueprint $table): void {
        $table->string('SKU')->primary();
        $table->string('NAME');
        $table->string('PRICE')->default('1.00');
        $table->dateTime('UPDATED_AT')->default('2026-11-09 10:00:00');
    });
    DB::connection($connection)->table($table)->insert($rows);
    DB::table('tenant_sources')->insert(['account' => $account, 'source_table' => $table]);
}

it('syncs each account source as its own definition', function (): void {
    // The same SKU and the same bytes in both sources. A shared watermark or
    // ledger would let the second account's rows be skipped.
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Acme anvil']]);
    connectSource('globex', [['SKU' => 'A-1', 'NAME' => 'Acme anvil']]);

    $this->artisan('sync:run', ['--now' => true])->assertSuccessful();

    expect(SyncRun::query()->orderBy('definition')->pluck('status', 'definition')->all())->toBe([
        'tenant-products-acme' => RunStatus::Completed,
        'tenant-products-globex' => RunStatus::Completed,
    ])
        ->and(Product::query()->orderBy('category')->pluck('category')->all())->toBe(['acme', 'globex'])
        ->and(app(TrackWatermark::class)->current('tenant-products-acme'))->not->toBeNull()
        ->and(app(TrackWatermark::class)->current('tenant-products-globex'))->not->toBeNull();
});

it('picks up a source connected after the application booted', function (): void {
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);

    expect(array_keys(app(SyncManager::class)->registered()))->toBe(['tenant-products-acme']);

    connectSource('initech', [['SKU' => 'S-1', 'NAME' => 'Stapler']]);

    $this->artisan('sync:run', ['name' => 'tenant-products-initech', '--now' => true])
        ->assertSuccessful();

    expect(Product::query()->where('category', 'initech')->value('name'))->toBe('Stapler');
});

it('resolves a provider definition again inside a queue worker', function (): void {
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->text('failed_job_ids');
        $table->text('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    config()->set('data-sync.queue.connection', 'sync');
    config()->set('queue.batching.database', 'testing');
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);

    $this->artisan('sync:run', ['name' => 'tenant-products-acme'])->assertSuccessful();

    expect(SyncRun::query()->sole()->status)->toBe(RunStatus::Completed)
        ->and(Product::query()->count())->toBe(1);
});

it('never calls a provider while the application boots', function (): void {
    config()->set('data-sync.syncs', [UnavailableDefinitionProvider::class]);

    // The boot path of the service provider, as on an HTTP request or the
    // first `migrate` of a deploy, before the provider's tables exist.
    app()->getProvider(DataSyncServiceProvider::class)->packageBooted();

    expect(fn () => app(SyncManager::class)->registered())
        ->toThrow(RuntimeException::class, 'The accounts table does not exist yet.');
});

it('registers a schedule for every provider definition', function (): void {
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);
    connectSource('globex', [['SKU' => 'G-1', 'NAME' => 'Globe']]);
    $schedule = new Schedule;

    app(RegisterSchedules::class)($schedule);

    expect(collect($schedule->events())->pluck('command')->implode("\n"))
        ->toContain('sync:run tenant-products-acme')
        ->toContain('sync:run tenant-products-globex');
});

it('applies overrides to provider definitions and rejects unknown names once providers run', function (): void {
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);
    connectSource('globex', [['SKU' => 'G-1', 'NAME' => 'Globe']]);
    config()->set('data-sync.overrides', ['tenant-products-acme' => ['enabled' => false]]);

    expect(array_keys(app(SyncManager::class)->enabled()))->toBe(['tenant-products-globex']);

    config()->set('data-sync.overrides', ['tenant-products-gone' => ['enabled' => false]]);

    // The boot check cannot know provider names, so it lets the key through.
    app(ConfigurationValidator::class)->validate();

    expect(fn () => app(SyncManager::class)->registered())
        ->toThrow(InvalidConfigurationException::class, '[data-sync.overrides.tenant-products-gone]');
});

it('rejects what a provider returns when it cannot be registered', function (): void {
    config()->set('data-sync.syncs', [WrongItemDefinitionProvider::class]);

    expect(fn () => app(SyncManager::class)->registered())
        ->toThrow(InvalidConfigurationException::class, 'WrongItemDefinitionProvider] definitions() returned a [stdClass]');

    config()->set('data-sync.syncs', [TenantSourcesProvider::class]);
    connectSource('acme.eu', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);

    // A dot would split the name's data-sync.overrides key.
    expect(fn () => app(SyncManager::class)->registered())
        ->toThrow(InvalidConfigurationException::class, 'name() returned [tenant-products-acme.eu]');
});

it('leaves the names of definitions listed by class as 2.0 accepted them', function (): void {
    // The provider name rule must not reach a class a 2.0 app already
    // registers, or a minor upgrade would stop it booting.
    config()->set('data-sync.syncs', [DottedNameProductsSync::class]);

    expect(array_keys(app(SyncManager::class)->registered()))->toBe(['products.daily']);
});

it('asks doctor for the watermarks table when a provider registers a database definition', function (): void {
    connectSource('acme', [['SKU' => 'A-1', 'NAME' => 'Anvil']]);
    Schema::drop('sync_watermarks');

    $this->artisan('sync:doctor')
        ->expectsOutputToContain('Missing the [sync_watermarks] table a database definition needs for its watermark.')
        ->assertFailed();
});
