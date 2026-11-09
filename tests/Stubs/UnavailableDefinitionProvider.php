<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\DefinitionProvider;
use RuntimeException;

/**
 * Stands in for a provider whose tables do not exist yet, as during the first
 * `migrate` of a fresh deploy.
 */
final class UnavailableDefinitionProvider implements DefinitionProvider
{
    public function definitions(): iterable
    {
        throw new RuntimeException('The accounts table does not exist yet.');
    }
}
