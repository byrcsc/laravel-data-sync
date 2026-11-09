<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\RegisterSchedules;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Readers\NdjsonReader;
use ByRcsc\LaravelDataSync\Support\QueueOptions;
use ByRcsc\LaravelDataSync\SyncManager;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\BadEndpointApiSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\BadKnobsApiSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\NegativeRetriesApiSync;
use Illuminate\Console\Scheduling\Schedule;
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

it('pins the api definition seams a host application overrides', function (): void {
    expect(declaredMethods(ApiSyncDefinition::class))->toBe([
        'endpoint',
        'format',
        'nextPage',
        'records',
        'request',
        'retries',
        'retryDelay',
        'source',
        'timeout',
        'watermarkFrom',
    ]);
});

it('seals the format and source an api definition cannot choose', function (): void {
    $definition = new ApiProductsSync;

    expect($definition->format())->toBeInstanceOf(NdjsonReader::class)
        ->and($definition->source()->diskName())->toBe('local')
        ->and($definition->source()->directory())->toBe('data-sync/staging/api-products')
        ->and((new ReflectionMethod(ApiSyncDefinition::class, 'format'))->isFinal())->toBeTrue()
        ->and((new ReflectionMethod(ApiSyncDefinition::class, 'source'))->isFinal())->toBeTrue();
});

it('defaults the http knobs to the decided values', function (): void {
    $definition = new ApiProductsSync;

    expect($definition->timeout())->toBe(30)
        ->and($definition->retries())->toBe(3)
        ->and($definition->retryDelay())->toBe(1000);
});

it('resolves api definitions through the manager like any other sync', function (): void {
    config()->set('data-sync.syncs', [ApiProductsSync::class]);

    $manager = app(SyncManager::class);

    expect($manager->definition('api-products'))->toBeInstanceOf(ApiProductsSync::class)
        ->and(array_keys($manager->registered()))->toBe(['api-products'])
        ->and(array_keys($manager->enabled()))->toBe(['api-products']);
});

it('honours the enabled and queue overrides for api definitions', function (): void {
    config()->set('data-sync.syncs', [ApiProductsSync::class]);
    config()->set('data-sync.overrides.api-products', [
        'queue' => ['connection' => 'redis', 'queue' => 'imports'],
    ]);

    $definition = app(SyncManager::class)->definition('api-products');
    $queueOptions = app(QueueOptions::class);

    expect($queueOptions->connection($definition))->toBe('redis')
        ->and($queueOptions->queue($definition))->toBe('imports');

    config()->set('data-sync.overrides.api-products.enabled', false);

    expect(app(SyncManager::class)->enabled())->toBe([]);
});

it('honours the schedule override for api definitions', function (): void {
    config()->set('data-sync.syncs', [ApiProductsSync::class]);
    config()->set('data-sync.overrides.api-products.schedule', '7 * * * *');
    $schedule = new Schedule;

    app(RegisterSchedules::class)($schedule);

    $run = collect($schedule->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'sync:run'));

    expect($run->command)->toContain('sync:run api-products')
        ->and($run->expression)->toBe('7 * * * *');
});

it('rejects an endpoint that is not an http url', function (): void {
    expect(fn () => app(DefinitionValidator::class)->validate(new BadEndpointApiSync))
        ->toThrow(InvalidDefinitionException::class, 'endpoint()');
});

it('rejects http knobs that cannot describe a request', function (): void {
    expect(fn () => app(DefinitionValidator::class)->validate(new BadKnobsApiSync))
        ->toThrow(InvalidDefinitionException::class, 'timeout()')
        ->and(fn () => app(DefinitionValidator::class)->validate(new NegativeRetriesApiSync))
        ->toThrow(InvalidDefinitionException::class, 'retries()');
});
