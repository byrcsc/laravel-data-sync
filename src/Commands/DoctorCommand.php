<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\InspectInstallation;

final class DoctorCommand extends Command
{
    protected $signature = 'sync:doctor
        {--strict : Treat warnings as failures}';

    protected $description = 'Check the data sync installation and operational dependencies';

    public function handle(InspectInstallation $inspect): int
    {
        $report = $inspect();

        foreach ($report->failures as $failure) {
            $this->components->error($failure);
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($report->failures !== []) {
            return self::FAILURE;
        }

        if ($report->warnings !== [] && $this->option('strict')) {
            $count = count($report->warnings);
            $this->components->error("Strict mode failed because {$count} warning(s) were reported.");

            return self::FAILURE;
        }

        $this->components->info('Data sync installation checks passed.');

        return self::SUCCESS;
    }
}
