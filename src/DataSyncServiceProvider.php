<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync;

use ByRcsc\LaravelDataSync\Commands\RunCommand;
use ByRcsc\LaravelDataSync\Commands\StatusCommand;
use ByRcsc\LaravelDataSync\Contracts\ChecksumVerifier;
use ByRcsc\LaravelDataSync\Support\Checksum;
use ByRcsc\LaravelDataSync\Support\ConfigurationValidator;
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
            ->hasCommands([
                RunCommand::class,
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
    }
}
