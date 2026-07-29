<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

final class DuplicateRowException extends DataSyncException
{
    public static function identity(string $definition, string $identity, int $firstRow, int $duplicateRow): self
    {
        return new self("[{$definition}] found duplicate identity [{$identity}] on rows {$firstRow} and {$duplicateRow}; onDuplicate() is set to fail.");
    }
}
