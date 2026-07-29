<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DataSyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-data-sync')
            ->hasConfigFile('data-sync')
            ->hasMigration('create_data_sync_tables');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SyncManager::class);
        $this->app->alias(SyncManager::class, 'data-sync');
    }
}
