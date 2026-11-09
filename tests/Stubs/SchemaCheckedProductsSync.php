<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

/**
 * A base for one-off definitions that each break the source schema check in
 * a different way.
 */
abstract class SchemaCheckedProductsSync extends DatabaseProductSyncDefinition
{
    public function name(): string
    {
        return 'schema-products';
    }
}
