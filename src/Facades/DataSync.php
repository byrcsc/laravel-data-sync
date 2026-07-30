<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Facades;

use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RunReport run(string $name, bool $force = false, bool $dryRun = false)
 */
final class DataSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SyncManager::class;
    }
}
