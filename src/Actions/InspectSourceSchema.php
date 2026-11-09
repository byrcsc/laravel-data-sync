<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use ByRcsc\LaravelDataSync\Support\SourceQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Checks a database definition against its source before any data is read.
 * A query over one table or view is checked against that table's columns with
 * a single schema read. Any other query is checked against the first page of
 * rows it returns.
 */
final readonly class InspectSourceSchema
{
    private const array BINARY_TYPES = [
        'binary', 'varbinary', 'image', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea',
    ];

    private const array TIMESTAMP_TYPES = [
        'date', 'datetime', 'datetime2', 'datetimeoffset', 'smalldatetime', 'timestamp', 'timestamptz',
    ];

    /**
     * SQL Server names the rowversion type `timestamp`, which holds binary
     * values rather than times.
     */
    private const array ROWVERSION_TYPES = ['rowversion', 'timestamp'];

    public function __construct(
        private SourceQuery $sourceQuery,
        private DefinitionValidator $validator,
    ) {}

    /**
     * Problems found in the source table, or null when the query is not a
     * single table or view and the schema cannot answer for it.
     *
     * @return list<string>|null
     */
    public function __invoke(DatabaseSyncDefinition $definition): ?array
    {
        $query = $this->sourceQuery->definitionQuery($definition);
        $table = $this->singleTable($query);

        if ($table === null) {
            return null;
        }

        $names = [];
        $types = [];

        foreach ($this->sourceQuery->schema($definition)->getColumns($table) as $column) {
            $names[] = $column['name'];
            $types[strtolower($column['name'])] = strtolower($column['type_name']);
        }

        if ($types === []) {
            return ["query() reads [{$table}], which does not exist on the [{$definition->connection()}] connection."];
        }

        $sqlServer = $this->sourceQuery->driver($definition) === 'sqlsrv';
        [$selected, $complete] = $this->selected($query, $names);
        $problems = [];

        foreach ($selected as $name => $column) {
            $type = $types[strtolower($column)] ?? null;

            if ($type === null) {
                $problems[] = "query() selects [{$column}], which [{$table}] does not have.";
            } elseif (in_array($type, self::BINARY_TYPES, true) || ($sqlServer && in_array($type, self::ROWVERSION_TYPES, true))) {
                $problems[] = "query() selects the binary column [{$name}], which a snapshot cannot store. Drop it from query(), or on SQL Server select it as text with CONVERT(varchar(max), {$column}, 1).";
            }
        }

        if ($complete) {
            foreach ($this->requiredFields($definition) as $field) {
                if (! array_key_exists($field->from(), $selected)) {
                    $problems[] = "fields() maps [{$field->name()}] from column [{$field->from()}], which query() does not select from [{$table}].";
                }
            }
        }

        foreach ($definition->keyColumns() as $key) {
            if (! isset($types[strtolower(Str::afterLast($key, '.'))])) {
                $problems[] = "keyColumns() names [{$key}], which [{$table}] does not have.";
            }
        }

        $watermark = $definition->watermark();

        if ($watermark instanceof Watermark) {
            $problem = $this->watermarkProblem($watermark, $table, $types, $sqlServer);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The check for a query the schema cannot answer for: the first row must
     * carry every key column and every column a required field reads.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function checkRow(DatabaseSyncDefinition $definition, array $row): array
    {
        $problems = [];

        foreach ($definition->keyColumns() as $key) {
            if (! array_key_exists(Str::afterLast($key, '.'), $row)) {
                $problems[] = "keyColumns() names [{$key}], but the rows query() returns have no such column.";
            }
        }

        foreach ($this->requiredFields($definition) as $field) {
            if (! array_key_exists($field->from(), $row)) {
                $problems[] = "fields() maps [{$field->name()}] from column [{$field->from()}], but the rows query() returns have no such column.";
            }
        }

        return $problems;
    }

    /**
     * A table or view name, when the query reads exactly one with no joins
     * and no unions.
     */
    private function singleTable(Builder $query): ?string
    {
        if (! is_string($query->from) || $query->joins !== null && $query->joins !== [] || $query->unions !== null && $query->unions !== []) {
            return null;
        }

        return (string) preg_replace('/\s+as\s+\S+$/i', '', trim($query->from));
    }

    /**
     * The names each row will carry, mapped to the table column behind each.
     * The map is incomplete when the select holds a raw expression.
     *
     * @param  list<string>  $names
     * @return array{array<string, string>, bool}
     */
    private function selected(Builder $query, array $names): array
    {
        $columns = $query->columns ?? ['*'];
        $selected = [];
        $complete = true;

        foreach ($columns as $column) {
            if (! is_string($column)) {
                $complete = false;

                continue;
            }

            $column = trim($column);

            if (Str::endsWith($column, '*')) {
                foreach ($names as $name) {
                    $selected[$name] = $name;
                }

                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $column) ?: [$column];
            $source = Str::afterLast($parts[0], '.');
            $selected[$parts[1] ?? $source] = $source;
        }

        return [$selected, $complete];
    }

    /**
     * Fields a row must carry. An optional field or one with a default may
     * read a column the source does not have.
     *
     * @return list<Field>
     */
    private function requiredFields(DatabaseSyncDefinition $definition): array
    {
        return array_values(array_filter(
            $this->validator->fields($definition),
            fn (Field $field): bool => ! $field->isOptional() && ! $field->hasDefault(),
        ));
    }

    /**
     * @param  array<string, string>  $types
     */
    private function watermarkProblem(Watermark $watermark, string $table, array $types, bool $sqlServer): ?string
    {
        $column = $watermark->column();
        $type = $types[strtolower(Str::afterLast($column, '.'))] ?? null;

        if ($type === null) {
            return "watermark() names column [{$column}], which [{$table}] does not have.";
        }

        $allowed = $watermark->mode() === 'rowversion'
            ? self::ROWVERSION_TYPES
            : array_diff(self::TIMESTAMP_TYPES, $sqlServer ? ['timestamp'] : []);

        if (in_array($type, $allowed, true)) {
            return null;
        }

        return "watermark() reads column [{$column}] as a {$watermark->mode()} watermark, but [{$table}] stores it as [{$type}].";
    }
}
