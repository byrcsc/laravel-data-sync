<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use ByRcsc\LaravelDataSync\Support\SourceQuery;

/**
 * Checks what a database definition can be checked for without a query. The
 * application must still boot while the source database is down, so the source
 * schema is checked when a fetch starts and by `sync:doctor`, never here.
 */
final readonly class DatabaseDefinitionValidator
{
    public function __construct(
        private SourceQuery $sourceQuery,
    ) {}

    public function validate(DatabaseSyncDefinition $definition): void
    {
        $this->validateConnection($definition);
        $this->validateKeyColumns($definition);
        $this->validateWatermark($definition);
        $this->validateQuery($definition);

        if ($definition->pageSize() < 1) {
            throw InvalidDefinitionException::invalidPageSize($definition::class);
        }
    }

    private function validateConnection(DatabaseSyncDefinition $definition): void
    {
        $connection = $definition->connection();

        if (! is_array(config("database.connections.{$connection}"))) {
            throw InvalidDefinitionException::unknownConnection($definition::class, $connection);
        }
    }

    private function validateKeyColumns(DatabaseSyncDefinition $definition): void
    {
        $keys = $definition->keyColumns();

        if ($keys === []) {
            throw InvalidDefinitionException::emptyKeyColumns($definition::class);
        }

        foreach ($keys as $key) {
            if (trim($key) === '') {
                throw InvalidDefinitionException::emptyKeyColumns($definition::class);
            }
        }
    }

    private function validateWatermark(DatabaseSyncDefinition $definition): void
    {
        $watermark = $definition->watermark();

        if ($watermark === null) {
            return;
        }

        if (trim($watermark->column()) === '') {
            throw InvalidDefinitionException::emptyWatermarkColumn($definition::class);
        }

        if ($watermark->overlapSeconds() < 0) {
            throw InvalidDefinitionException::invalidWatermarkOverlap($definition::class);
        }

        $driver = $this->sourceQuery->driver($definition);

        if ($watermark->mode() === 'rowversion' && $driver !== 'sqlsrv') {
            throw InvalidDefinitionException::rowVersionNeedsSqlServer(
                $definition::class,
                $definition->connection(),
                (string) $driver,
            );
        }
    }

    private function validateQuery(DatabaseSyncDefinition $definition): void
    {
        $query = $this->sourceQuery->definitionQuery($definition);

        if ($query->from === null) {
            throw InvalidDefinitionException::queryWithoutSource($definition::class);
        }

        if ($query->limit !== null
            || $query->offset !== null
            || $query->unionLimit !== null
            || $query->unionOffset !== null) {
            throw InvalidDefinitionException::queryLimitsRows($definition::class);
        }
    }
}
