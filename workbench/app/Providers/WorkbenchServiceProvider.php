<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Syncs\ProductsSync;

use function Orchestra\Testbench\workbench_path;

/**
 * Configures the demo app.
 *
 * This stands in for the `config/data-sync.php`, `config/filesystems.php` and
 * `config/database.php` edits a real application would make when installing the
 * package. Keeping it in one file means the workbench carries no copies of the
 * framework's config files that would silently rot against future Laravel
 * releases.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->useDatabasePath(workbench_path('database'));

        config([
            // The demo needs a file-backed database that a separate
            // `queue:work` process can also see. Testbench otherwise falls back
            // to its in-memory `testing` connection.
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => workbench_path('database', 'database.sqlite'),

            'filesystems.disks.incoming' => [
                'driver' => 'local',
                'root' => workbench_path('storage', 'app', 'incoming'),
                'throw' => true,
            ],
            // Where the package does its own work: staging, archive and
            // preserved failed files all land under here.
            'filesystems.disks.sync-work' => [
                'driver' => 'local',
                'root' => workbench_path('storage', 'app'),
                'throw' => true,
            ],

            'data-sync.syncs' => [
                ProductsSync::class,
            ],
            'data-sync.staging.disk' => 'sync-work',
            'data-sync.archive.disk' => 'sync-work',
            'data-sync.failed.disk' => 'sync-work',
        ]);
    }
}
