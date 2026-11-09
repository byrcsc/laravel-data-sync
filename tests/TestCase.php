<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests;

use ByRcsc\LaravelDataSync\DataSyncServiceProvider;
use ByRcsc\LaravelDataSync\Support\TableNames;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DataSyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'data_sync_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'data_sync_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'sqlite') {
            Schema::dropAllTables();
        }

        $this->runDataSyncMigration();
    }

    protected function runDataSyncMigration(): void
    {
        foreach (['create_data_sync_tables', 'upgrade_data_sync_tables_to_v2'] as $name) {
            $migration = require __DIR__."/../database/migrations/{$name}.php.stub";

            $migration->up();
        }
    }

    protected function dropDataSyncTables(): void
    {
        Schema::dropIfExists(TableNames::watermarks());
        Schema::dropIfExists(TableNames::rowFailures());
        Schema::dropIfExists(TableNames::runs());
        Schema::dropIfExists(TableNames::files());
    }
}
