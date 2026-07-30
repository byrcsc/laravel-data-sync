<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use ReflectionMethod;

final readonly class RegisterSchedules
{
    public function __construct(
        private Container $container,
        private ConfigurationValidator $configuration,
    ) {}

    public function __invoke(Schedule $schedule): void
    {
        if (config('data-sync.register_schedules', true) === false) {
            return;
        }

        $this->configuration->validate();
        $schedule->command('sync:prune')->daily();

        foreach ($this->definitions() as $definition) {
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

    /**
     * @return list<Definition>
     */
    private function definitions(): array
    {
        $configured = config('data-sync.syncs', []);
        $classes = is_array($configured) ? $configured : [];
        $definitions = [];

        foreach ($classes as $class) {
            if (! is_string($class)) {
                continue;
            }

            $definition = $this->container->make($class);

            if ($definition instanceof Definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}
