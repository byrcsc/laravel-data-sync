<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\DefinitionProvider;
use Illuminate\Support\Facades\DB;

/**
 * Reads the sources each account has connected from the application's own
 * table, and registers a connection and a definition for each.
 */
final class TenantSourcesProvider implements DefinitionProvider
{
    public function definitions(): iterable
    {
        foreach (DB::table('tenant_sources')->orderBy('account')->get() as $source) {
            $connection = "tenant_{$source->account}";

            // A real application builds this from the account's stored, and
            // encrypted, credentials. The test points every account at the
            // test source database.
            config()->set("database.connections.{$connection}", config('database.connections.source'));

            yield new TenantProductsSync($source->account, $connection, $source->source_table);
        }
    }
}
