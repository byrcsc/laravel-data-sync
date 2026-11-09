<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Contracts;

use ByRcsc\LaravelDataSync\Definitions\Definition;

/**
 * Registers definitions at runtime, such as one database definition for each
 * source an account connects. List the provider class in `data-sync.syncs`.
 *
 * The package calls definitions() in every process that resolves definitions:
 * `sync:*` commands, queue workers, and the scheduler. It never calls it while
 * the application boots, so a provider may read the application's own tables.
 * Return the same name() for the same source on every call, because the
 * watermark, the ledger, and the run history are all keyed by it.
 */
interface DefinitionProvider
{
    /**
     * @return iterable<array-key, Definition>
     */
    public function definitions(): iterable;
}
