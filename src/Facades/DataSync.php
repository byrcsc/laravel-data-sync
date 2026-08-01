<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Facades;

use ByRcsc\LaravelDataSync\Data\RunReport;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, Definition> registered()
 * @method static array<string, Definition> enabled()
 * @method static Definition definition(string $name)
 * @method static RunReport run(string $name, bool $force = false, bool $dryRun = false)
 * @method static RunReport queue(string $name, bool $force = false)
 */
final class DataSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SyncManager::class;
    }
}
