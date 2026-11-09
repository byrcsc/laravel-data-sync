<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

final readonly class SourceQuery
{
    /**
     * The name a union query is wrapped under, so the package's own clauses
     * apply to every part of the union rather than the first.
     */
    public const string UNION_ALIAS = 'data_sync_source';

    public function __construct(
        private DatabaseManager $database,
    ) {}

    /**
     * The query exactly as the definition built it. Building it opens no
     * connection, so boot validation can inspect it while the source is down.
     */
    public function definitionQuery(DatabaseSyncDefinition $definition): Builder
    {
        return $definition->query(
            $this->database->connection($definition->connection())->query(),
        );
    }

    /**
     * The query the package reads from: unordered, and wrapped when it is a
     * union.
     */
    public function base(DatabaseSyncDefinition $definition): Builder
    {
        $query = $this->definitionQuery($definition)->reorder();

        if ($query->unions === null || $query->unions === []) {
            return $query;
        }

        return $query->newQuery()->fromSub($query, self::UNION_ALIAS);
    }

    public function schema(DatabaseSyncDefinition $definition): SchemaBuilder
    {
        return $this->database->connection($definition->connection())->getSchemaBuilder();
    }

    public function driver(DatabaseSyncDefinition $definition): ?string
    {
        $driver = config("database.connections.{$definition->connection()}.driver");

        return is_string($driver) ? $driver : null;
    }
}
