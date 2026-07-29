<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Facades;

use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Support\Facades\Facade;

final class DataSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SyncManager::class;
    }
}
