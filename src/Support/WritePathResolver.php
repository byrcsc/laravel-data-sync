<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ReflectionMethod;

final class WritePathResolver
{
    public function for(SyncDefinition $definition): WritePath
    {
        $beforeSave = new ReflectionMethod($definition, 'beforeSave');
        $afterSave = new ReflectionMethod($definition, 'afterSave');

        if ($beforeSave->getDeclaringClass()->getName() !== SyncDefinition::class) {
            return WritePath::Eloquent;
        }

        return $afterSave->getDeclaringClass()->getName() !== SyncDefinition::class
            ? WritePath::Eloquent
            : WritePath::Bulk;
    }
}
