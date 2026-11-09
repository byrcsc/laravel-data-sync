<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ReflectionMethod;

final class WriteStrategyResolver
{
    public function for(SyncDefinition $definition): WriteStrategy
    {
        $beforeSave = new ReflectionMethod($definition, 'beforeSave');
        $afterSave = new ReflectionMethod($definition, 'afterSave');

        if ($beforeSave->getDeclaringClass()->getName() !== SyncDefinition::class) {
            return WriteStrategy::Eloquent;
        }

        return $afterSave->getDeclaringClass()->getName() !== SyncDefinition::class
            ? WriteStrategy::Eloquent
            : WriteStrategy::Bulk;
    }
}
