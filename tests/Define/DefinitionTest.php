<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\ExistingFilePolicy;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Enums\UnmatchedFilenamePolicy;
use ByRcsc\LaravelDataSync\Enums\WriteMode;
use ByRcsc\LaravelDataSync\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\InvalidCaptureProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\MissingMatchProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\Product;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\UnknownAttributeProductsSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->unique();
        $table->string('name');
        $table->integer('price')->default(0);
        $table->string('category')->nullable();
        $table->json('meta')->nullable();
    });
});

it('maps, transforms, defaults, and exposes the decided definition defaults', function (): void {
    $field = Field::make('price', from: 'PRICE')
        ->transform(fn (string $value): int => (int) $value * 100)
        ->default(0);
    $definition = new ProductsSync;

    expect($field->name())->toBe('price')
        ->and($field->source())->toBe('PRICE')
        ->and($field->apply('12'))->toBe(1200)
        ->and($field->isOptional())->toBeTrue()
        ->and($field->hasDefault())->toBeTrue()
        ->and($field->defaultValue())->toBe(0)
        ->and($definition->writeMode())->toBe(WriteMode::Upsert)
        ->and($definition->onDuplicate())->toBe(DuplicatePolicy::LastWins)
        ->and($definition->onUnknownColumns())->toBe(UnknownColumnPolicy::Ignore)
        ->and($definition->chunkSize())->toBe(1000)
        ->and($definition->maxErrors())->toBeNull();
});

it('provides all decided string backed enum cases', function (): void {
    expect(array_column(RunStatus::cases(), 'value'))->toContain('completed_with_errors')
        ->and(array_column(WriteMode::cases(), 'value'))->toBe(['upsert', 'create_only', 'update_only'])
        ->and(array_column(DuplicatePolicy::cases(), 'value'))->toBe(['last_wins', 'first_wins', 'fail'])
        ->and(array_column(UnknownColumnPolicy::cases(), 'value'))->toBe(['strict', 'ignore', 'capture'])
        ->and(array_column(ExistingFilePolicy::cases(), 'value'))->toBe(['overwrite', 'fail'])
        ->and(array_column(UnmatchedFilenamePolicy::cases(), 'value'))->toBe(['skip', 'fail']);
});

it('resolves explicit config registrations through the manager', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);

    expect(app(SyncManager::class)->definition('products'))->toBeInstanceOf(ProductsSync::class)
        ->and(array_keys(app(SyncManager::class)->registered()))->toBe(['products']);
});

it('rejects match keys that are not mapped', function (): void {
    app(DefinitionValidator::class)->validate(new MissingMatchProductsSync);
})->throws(InvalidDefinitionException::class, 'matchOn() references [external_id]');

it('rejects nonexistent model attributes during definition resolution', function (): void {
    app(DefinitionValidator::class)->validate(new UnknownAttributeProductsSync);
})->throws(InvalidDefinitionException::class, '[not_a_product_attribute]');

it('rejects capture targets without an array compatible cast', function (): void {
    app(DefinitionValidator::class)->validate(new InvalidCaptureProductsSync);
})->throws(InvalidDefinitionException::class, '[name]');

it('rejects overrides for unregistered definitions and invalid retention', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    config()->set('data-sync.overrides.unknown.enabled', true);

    expect(fn () => app(ConfigurationValidator::class)->validate())
        ->toThrow(InvalidConfigurationException::class, 'data-sync.overrides.unknown');

    config()->set('data-sync.overrides', []);
    config()->set('data-sync.retention.runs_days', -1);

    expect(fn () => app(ConfigurationValidator::class)->validate())
        ->toThrow(InvalidConfigurationException::class, 'data-sync.retention.runs_days');

    config()->set('data-sync.retention.runs_days', 90);
    config()->set('data-sync.overrides.products.chunk_size', 0);

    expect(fn () => app(ConfigurationValidator::class)->validate())
        ->toThrow(InvalidConfigurationException::class, 'data-sync.overrides.products.chunk_size');
});

it('accepts a castable capture target on the model', function (): void {
    expect((new Product)->hasCast('meta', ['array']))->toBeTrue();
});
