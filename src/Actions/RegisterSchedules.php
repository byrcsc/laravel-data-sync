<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;

final readonly class RegisterSchedules
{
    public function __construct(
        private ConfigurationValidator $configuration,
    ) {}

    public function __invoke(Schedule $schedule): void
    {
        if (config('data-sync.register_schedules', true) === false) {
            return;
        }

        $schedule->command('sync:prune')->daily();

        foreach ($this->configuration->resolve() as $definition) {
            $name = $definition->name();

            if (config("data-sync.overrides.{$name}.enabled", true) === false) {
                continue;
            }

            $override = config("data-sync.overrides.{$name}.schedule");

            if ($override === false) {
                continue;
            }

            $hasDefinitionSchedule = (new ReflectionMethod($definition, 'schedule'))
                ->getDeclaringClass()
                ->getName() !== Definition::class;

            if (! is_string($override) && ! $hasDefinitionSchedule) {
                continue;
            }

            $event = $schedule->command("sync:run {$name}");

            if (is_string($override)) {
                $event->cron($override);

                continue;
            }

            $definition->schedule($event);
        }
    }
}
