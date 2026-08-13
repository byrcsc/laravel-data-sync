<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync;

use ByRcsc\LaravelDataSync\Actions\RegisterSchedules;
use ByRcsc\LaravelDataSync\Commands\DoctorCommand;
use ByRcsc\LaravelDataSync\Commands\MakeSyncCommand;
use ByRcsc\LaravelDataSync\Commands\PruneCommand;
use ByRcsc\LaravelDataSync\Commands\RetryCommand;
use ByRcsc\LaravelDataSync\Commands\RunCommand;
use ByRcsc\LaravelDataSync\Commands\StatusCommand;
use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Support\Checksum;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DataSyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-data-sync')
            ->hasConfigFile('data-sync')
            ->hasMigration('create_data_sync_tables')
            ->hasMigration('add_data_sync_cursors_table')
            ->hasCommands([
                DoctorCommand::class,
                MakeSyncCommand::class,
                RunCommand::class,
                PruneCommand::class,
                RetryCommand::class,
                StatusCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(ChecksumVerifier::class, Checksum::class);
        $this->app->singleton(SyncManager::class);
        $this->app->alias(SyncManager::class, 'data-sync');
    }

    public function packageBooted(): void
    {
        $this->app->make(ConfigurationValidator::class)->validate();
        $this->app->booted(function (): void {
            ($this->app->make(RegisterSchedules::class))(
                $this->app->make(Schedule::class),
            );
        });
    }
}
