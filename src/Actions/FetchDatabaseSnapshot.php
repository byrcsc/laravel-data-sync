<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use ByRcsc\LaravelDataSync\Exceptions\DatabaseFetchException;
use ByRcsc\LaravelDataSync\Support\SourceQuery;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a definition's query in key order, one bounded page at a time, and
 * hands each row to the shared snapshot action. It only reads: nothing is
 * written to the source connection.
 */
final readonly class FetchDatabaseSnapshot
{
    public function __construct(
        private StageSnapshot $stageSnapshot,
        private SourceQuery $sourceQuery,
        private InspectSourceSchema $inspectSchema,
    ) {}

    public function __invoke(DatabaseSyncDefinition $definition, bool $force = false): DiscoveryResult
    {
        return ($this->stageSnapshot)(
            $definition,
            fn (mixed $stored): Generator => $this->read($definition, $stored),
            fn (Throwable $exception): Throwable => DatabaseFetchException::fetchFailed(
                $definition->name(),
                $definition->connection(),
                $exception,
            ),
            $force,
        );
    }

    /**
     * Every page query carries the same window, fixed before the first page, so
     * all pages agree on which rows belong to the run.
     *
     * @return Generator<int, array<string, mixed>, mixed, mixed>
     */
    private function read(DatabaseSyncDefinition $definition, mixed $stored): Generator
    {
        $problems = ($this->inspectSchema)($definition);
        $this->guardSchema($definition, $problems ?? []);

        [$window, $pending] = $this->window($definition, $this->sourceQuery->base($definition), $stored);
        $keys = $definition->keyColumns();
        $last = null;

        do {
            $page = clone $window;

            foreach ($keys as $key) {
                $page->orderBy($key);
            }

            if ($last !== null) {
                $this->after($page, $keys, $last);
            }

            $rows = $page->limit($definition->pageSize())->get();
            $row = [];

            if ($problems === null && $last === null && $rows->isNotEmpty()) {
                // The schema could not answer for this query, so the first row
                // is checked instead.
                $this->guardSchema($definition, $this->inspectSchema->checkRow($definition, $this->columns($rows->first())));
            }

            foreach ($rows as $record) {
                $row = $this->columns($record);
                $this->guardEncoding($definition, $row);

                yield $row;
            }

            if ($row !== []) {
                $last = $this->keyValues($definition, $row);
            }
        } while ($rows->count() === $definition->pageSize());

        return $pending;
    }

    /**
     * @param  list<string>  $problems
     */
    private function guardSchema(DatabaseSyncDefinition $definition, array $problems): void
    {
        if ($problems !== []) {
            throw DatabaseFetchException::sourceMismatch($definition->name(), $problems);
        }
    }

    /**
     * PDO names each column by its select alias, which a snapshot line keys on.
     *
     * @return array<string, mixed>
     */
    private function columns(object $record): array
    {
        $row = [];

        foreach (get_object_vars($record) as $column => $value) {
            $row[(string) $column] = $value;
        }

        return $row;
    }

    /**
     * Keyset paging: rows whose key sorts after the last row read. SQL Server
     * has no row-value comparison, so a composite key expands to
     * `(a > ?) OR (a = ? AND b > ?)` and so on.
     *
     * @param  list<string>  $keys
     * @param  list<mixed>  $last
     */
    private function after(Builder $page, array $keys, array $last): void
    {
        $page->where(function (Builder $query) use ($keys, $last): void {
            foreach ($keys as $position => $key) {
                $query->orWhere(function (Builder $query) use ($keys, $last, $position, $key): void {
                    for ($earlier = 0; $earlier < $position; $earlier++) {
                        $query->where($keys[$earlier], '=', $last[$earlier]);
                    }

                    $query->where($key, '>', $last[$position]);
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function keyValues(DatabaseSyncDefinition $definition, array $row): array
    {
        $values = [];

        foreach ($definition->keyColumns() as $key) {
            $column = Str::afterLast($key, '.');

            if (! array_key_exists($column, $row)) {
                throw DatabaseFetchException::missingKeyColumn($definition->name(), $key);
            }

            if ($row[$column] === null) {
                throw DatabaseFetchException::nullKey($definition->name(), $key);
            }

            $values[] = $row[$column];
        }

        return $values;
    }

    /**
     * A rowversion or varbinary value arrives as raw bytes, or as a stream on
     * pgsql, which the snapshot's JSON encoding rejects with a message that
     * names no column.
     *
     * @param  array<string, mixed>  $row
     */
    private function guardEncoding(DatabaseSyncDefinition $definition, array $row): void
    {
        foreach ($row as $column => $value) {
            if (is_resource($value) || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))) {
                throw DatabaseFetchException::binaryColumn($definition->name(), $column);
            }
        }
    }

    /**
     * The query restricted to this run's rows, and the watermark the run
     * commits when it completes.
     *
     * @return array{Builder, mixed}
     */
    private function window(DatabaseSyncDefinition $definition, Builder $base, mixed $stored): array
    {
        $watermark = $definition->watermark();

        if (! $watermark instanceof Watermark) {
            return [$base, null];
        }

        $lower = $this->storedBound($definition, $watermark, $stored);

        return $watermark->mode() === 'rowversion'
            ? $this->rowVersionWindow($watermark, $base, $lower)
            : $this->timestampWindow($watermark, $base, $lower);
    }

    private function storedBound(DatabaseSyncDefinition $definition, Watermark $watermark, mixed $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $mode = is_array($stored) && is_string($stored['mode'] ?? null) ? $stored['mode'] : 'unknown';
        $column = is_array($stored) && is_string($stored['column'] ?? null) ? $stored['column'] : 'unknown';
        $value = is_array($stored) ? ($stored['value'] ?? null) : null;

        if ($mode !== $watermark->mode() || $column !== $watermark->column() || ! is_string($value)) {
            throw DatabaseFetchException::watermarkChanged(
                $definition->name(),
                $mode,
                $column,
                $watermark->mode(),
                $watermark->column(),
            );
        }

        return $value;
    }

    /**
     * The upper bound is the highest value present when the run starts, so a
     * row updated during the read lands above it and in the next run. Reading
     * up to "the highest value seen" instead would skip that row whenever a
     * later row carried a higher value.
     *
     * @return array{Builder, mixed}
     */
    private function timestampWindow(Watermark $watermark, Builder $base, ?string $lower): array
    {
        $column = $watermark->column();
        $newest = clone $base;

        if ($lower !== null) {
            $newest->where($column, '>', $lower);
        }

        // The value is kept exactly as the driver returned it, never parsed,
        // so a datetime2 keeps all 7 fractional digits.
        $max = $newest->max($column);
        $upper = is_scalar($max) ? (string) $max : $lower;

        if ($upper === null) {
            // No row has a value yet, so there is nothing to bound.
            return [$base, null];
        }

        $from = $this->withOverlap($lower, $watermark->overlapSeconds());
        $window = (clone $base)->where(function (Builder $query) use ($column, $from, $upper): void {
            if ($from === null) {
                // The first run reads every row, including rows that have no
                // value in the watermark column yet.
                $query->where($column, '<=', $upper)->orWhereNull($column);

                return;
            }

            $query->where($column, '>', $from)->where($column, '<=', $upper);
        });

        return [$window, $this->stored($watermark, $upper)];
    }

    /**
     * MIN_ACTIVE_ROWVERSION() is the lowest value an open transaction can still
     * commit, so every row below it is committed and a row that commits later
     * lands in the next run. The bound is compared through CONVERT on the
     * parameter side only, so an index on the column stays usable.
     *
     * @return array{Builder, mixed}
     */
    private function rowVersionWindow(Watermark $watermark, Builder $base, ?string $lower): array
    {
        $bound = $base->getConnection()->selectOne(
            'select convert(varchar(18), min_active_rowversion(), 1) as bound',
        );
        $upper = is_object($bound) && is_string($bound->bound ?? null) ? $bound->bound : '';
        $column = $watermark->column();
        $window = (clone $base)
            ->where($column, '<', new Expression('convert(binary(8), ?, 1)'))
            ->addBinding($upper, 'where');

        if ($lower !== null) {
            $window->where($column, '>=', new Expression('convert(binary(8), ?, 1)'))
                ->addBinding($lower, 'where');
        }

        return [$window, $this->stored($watermark, $upper)];
    }

    /**
     * The overlap is subtracted in PHP and the result truncated to
     * milliseconds, which legacy `datetime` columns accept. Truncating only
     * moves the bound down, so it widens the re-read and never narrows it.
     */
    private function withOverlap(?string $lower, int $overlapSeconds): ?string
    {
        if ($lower === null || $overlapSeconds === 0) {
            return $lower;
        }

        $value = trim($lower);
        $format = 'Y-m-d H:i:s';

        if (str_contains($value, '.')) {
            $format .= '.v';
        }

        if (preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/i', $value) === 1) {
            $format .= 'P';
        }

        return CarbonImmutable::parse($value)->subSeconds($overlapSeconds)->format($format);
    }

    /**
     * @return array{mode: string, column: string, value: string}
     */
    private function stored(Watermark $watermark, string $value): array
    {
        return [
            'mode' => $watermark->mode(),
            'column' => $watermark->column(),
            'value' => $value,
        ];
    }
}
