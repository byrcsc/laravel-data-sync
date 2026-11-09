<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Commands\Command;
use ByRcsc\LaravelDataSync\DataSyncServiceProvider;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\SnapshotSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Events\SyncEvent;
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
        ApiSyncDefinition::class,
        Command::class,
        DatabaseSyncDefinition::class,
        DataSyncException::class,
        Definition::class,
        SnapshotSyncDefinition::class,
        SyncDefinition::class,
        TransferDefinition::class,
        SyncEvent::class,
    ]);

arch('the provider is the only package bootstrap')
    ->expect(DataSyncServiceProvider::class)
    ->toOnlyBeUsedIn([]);

arch('the manager does not depend on framework facades')
    ->expect(SyncManager::class)
    ->not->toUse('Illuminate\Support\Facades');

arch('definitions stay above the action and job layers')
    ->expect('ByRcsc\LaravelDataSync\Definitions')
    ->not->toUse([
        'ByRcsc\LaravelDataSync\Actions',
        'ByRcsc\LaravelDataSync\Jobs',
    ]);

arch('support helpers stay below the model layer')
    ->expect('ByRcsc\LaravelDataSync\Support')
    ->not->toUse('ByRcsc\LaravelDataSync\Models');
