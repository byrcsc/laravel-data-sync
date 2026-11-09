<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

use ByRcsc\LaravelDataSync\Support\TableNames;
use Throwable;

final class DatabaseFetchException extends DataSyncException
{
    public static function fetchFailed(string $definition, string $connection, Throwable $previous): self
    {
        return new self(
            "[{$definition}] could not read from the [{$connection}] connection: {$previous->getMessage()}",
            previous: $previous,
        );
    }

    /**
     * @param  list<string>  $problems
     */
    public static function sourceMismatch(string $definition, array $problems): self
    {
        return new self("[{$definition}] does not match its source. ".implode(' ', $problems));
    }

    public static function binaryColumn(string $definition, string $column): self
    {
        return new self(
            "[{$definition}] read a binary value from column [{$column}], which a snapshot cannot store. Drop the column from query(), or on SQL Server select it as text with CONVERT(varchar(max), {$column}, 1).",
        );
    }

    public static function missingKeyColumn(string $definition, string $key): self
    {
        return new self(
            "[{$definition}] keyColumns() names [{$key}], but the rows query() returns have no such column. Select it in query().",
        );
    }

    public static function nullKey(string $definition, string $key): self
    {
        return new self(
            "[{$definition}] read a row whose key column [{$key}] is null. keyColumns() must name columns that are never null.",
        );
    }

    public static function watermarkChanged(
        string $definition,
        string $storedMode,
        string $storedColumn,
        string $mode,
        string $column,
    ): self {
        $table = TableNames::watermarks();

        return new self(
            "[{$definition}] watermark() now reads {$mode} column [{$column}], but the stored watermark holds a {$storedMode} value for column [{$storedColumn}]. Delete the [{$table}] row where definition is [{$definition}], and the next run reads every row again.",
        );
    }
}
