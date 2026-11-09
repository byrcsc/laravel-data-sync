<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use Illuminate\Database\Query\Builder;

/**
 * Reads rows from a connection in `config/database.php` into a snapshot. The
 * package only reads from the source: it never writes to the connection.
 */
abstract class DatabaseSyncDefinition extends SnapshotSyncDefinition
{
    /**
     * The name of a connection in `database.connections`.
     */
    abstract public function connection(): string;

    /**
     * The base query only. The package adds the watermark window, orders by
     * keyColumns(), and pages the result, so the query must not set its own
     * limit or offset. Any ordering it sets is replaced.
     */
    abstract public function query(Builder $query): Builder;

    /**
     * Source columns that order rows uniquely. List every column of a
     * composite key. A qualified name such as `orders.id` is allowed, and each
     * row must carry the column under its unqualified name.
     *
     * @return list<string>
     */
    abstract public function keyColumns(): array;

    /**
     * Null reads the whole query on every run, which suits small reference
     * tables: an unchanged table produces the same snapshot and the ledger
     * skips it.
     */
    public function watermark(): ?Watermark
    {
        return null;
    }

    /**
     * Rows read per page query.
     */
    public function pageSize(): int
    {
        return 1000;
    }
}
