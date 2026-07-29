<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Commands\Command;
use ByRcsc\LaravelDataSync\DataSyncServiceProvider;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Exceptions\DataSyncException;
use ByRcsc\LaravelDataSync\SyncManager;

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('ByRcsc\LaravelDataSync')
    ->toUseStrictTypes();

arch('classes are final by default')
    ->expect('ByRcsc\LaravelDataSync')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        Command::class,
        DataSyncException::class,
        SyncDefinition::class,
    ]);

arch('the provider is the only package bootstrap')
    ->expect(DataSyncServiceProvider::class)
    ->toOnlyBeUsedIn([]);

arch('the manager does not depend on framework facades')
    ->expect(SyncManager::class)
    ->not->toUse('Illuminate\Support\Facades');

arch('support helpers stay below the model layer')
    ->expect('ByRcsc\LaravelDataSync\Support')
    ->not->toUse('ByRcsc\LaravelDataSync\Models');
