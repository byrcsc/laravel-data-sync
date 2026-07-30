<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\RegisterSchedules;
use ByRcsc\LaravelDataSync\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ScheduledProductsSync;
use Illuminate\Console\Scheduling\Schedule;

it('registers definition schedules and the daily prune command', function (): void {
    config()->set('data-sync.syncs', [ScheduledProductsSync::class]);
    $schedule = new Schedule;

    app(RegisterSchedules::class)($schedule);

    $events = collect($schedule->events());
    expect($events)->toHaveCount(2)
        ->and($events->pluck('command')->implode("\n"))->toContain('sync:run scheduled-products')
        ->and($events->pluck('command')->implode("\n"))->toContain('sync:prune');
    $sync = $events->first(fn ($event): bool => str_contains((string) $event->command, 'sync:run'));
    $prune = $events->first(fn ($event): bool => str_contains((string) $event->command, 'sync:prune'));
    expect($sync->expression)->toBe('*/5 * * * *')
        ->and($prune->expression)->toBe('0 0 * * *');
});

it('honours the kill switch, per-sync disabling, and cron overrides', function (): void {
    config()->set('data-sync.syncs', [
        ScheduledProductsSync::class,
        ProductsSync::class,
    ]);
    config()->set('data-sync.overrides', [
        'scheduled-products' => ['schedule' => false],
        'products' => ['schedule' => '7 * * * *'],
    ]);
    $schedule = new Schedule;

    app(RegisterSchedules::class)($schedule);

    $events = collect($schedule->events());
    expect($events)->toHaveCount(2)
        ->and($events->pluck('command')->implode("\n"))->not->toContain('scheduled-products');
    $sync = $events->first(fn ($event): bool => str_contains((string) $event->command, 'sync:run'));
    expect($sync->command)->toContain('sync:run products')
        ->and($sync->expression)->toBe('7 * * * *');

    config()->set('data-sync.register_schedules', false);
    $disabled = new Schedule;
    app(RegisterSchedules::class)($disabled);

    expect($disabled->events())->toBe([]);
});

it('rejects non-operational and unknown per-sync overrides', function (): void {
    config()->set('data-sync.syncs', [ProductsSync::class]);
    config()->set('data-sync.overrides.products.fields', []);

    expect(fn () => app(ConfigurationValidator::class)->validate())
        ->toThrow(InvalidConfigurationException::class, 'operational keys');

    config()->set('data-sync.overrides', ['missing' => ['enabled' => false]]);

    expect(fn () => app(ConfigurationValidator::class)->validate())
        ->toThrow(InvalidConfigurationException::class, 'does not match');
});
