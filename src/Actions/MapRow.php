<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Exceptions\SchemaMismatchException;
use Throwable;

final class MapRow
{
    public function __construct(
        private readonly DefinitionValidator $validator,
    ) {}

    /**
     * Throws when the header lacks a required column, or has an extra one under
     * the strict policy. Returns the header columns no field maps.
     *
     * @param  list<string|int>  $header
     * @return list<string|int>
     */
    public function validateHeader(SyncDefinition $definition, array $header): array
    {
        // The package writes an API snapshot itself, so one with no columns is a
        // feed that went quiet rather than a file missing its header.
        if ($header === [] && $definition instanceof ApiSyncDefinition) {
            return [];
        }

        $fields = $this->validator->fields($definition);
        $missing = [];

        foreach ($fields as $field) {
            if (in_array($field->from(), $header, true)) {
                continue;
            }

            if ($field->isOptional() || $field->hasDefault()) {
                continue;
            }

            $missing[] = $field->from();
        }

        if ($missing !== []) {
            throw SchemaMismatchException::missingColumns(
                $definition::class,
                $missing,
                $header,
            );
        }

        $known = array_map(
            fn (Field $field): string|int => $field->from(),
            $fields,
        );
        $unmappedColumns = array_values(array_filter(
            $header,
            fn (string|int $column): bool => ! in_array($column, $known, true),
        ));

        if ($unmappedColumns !== [] && $definition->onUnknownColumns() === UnknownColumnPolicy::Strict) {
            throw SchemaMismatchException::unknownColumns(
                $definition::class,
                $unmappedColumns,
                $header,
            );
        }

        return $unmappedColumns;
    }

    /**
     * @param  array<string|int, string|null>  $raw
     * @param  list<string|int>  $unmappedColumns
     * @return array<string, mixed>
     */
    public function __invoke(
        SyncDefinition $definition,
        array $raw,
        array $unmappedColumns,
    ): array {
        $mapped = [];

        foreach ($this->validator->fields($definition) as $field) {
            if (! array_key_exists($field->from(), $raw)) {
                $mapped[$field->name()] = $field->hasDefault()
                    ? $field->defaultValue()
                    : null;

                continue;
            }

            $mapped[$field->name()] = $field->apply($raw[$field->from()]);
        }

        if ($definition->onUnknownColumns() !== UnknownColumnPolicy::Capture) {
            return $mapped;
        }

        $unmapped = [];

        foreach ($unmappedColumns as $column) {
            $unmapped[(string) $column] = $raw[$column] ?? null;
        }

        $target = $definition->captureUnmappedInto();

        if ($target !== null) {
            $mapped[$target] = $unmapped;
        }

        return $mapped;
    }

    /**
     * Maps one row and, unless told not to, runs the definition's prepare().
     * A failure in either step is kept on the row so ProcessChunk records it
     * as a row failure rather than failing the run.
     *
     * @param  array<string|int, string|null>  $raw
     * @param  list<string|int>  $unmappedColumns
     */
    public function toChunkRow(
        SyncDefinition $definition,
        int $rowNumber,
        array $raw,
        array $unmappedColumns,
        bool $duplicate = false,
        bool $prepare = true,
    ): ChunkRow {
        try {
            $mapped = $this($definition, $raw, $unmappedColumns);

            if ($prepare) {
                $mapped = $definition->prepare($mapped);
            }

            return new ChunkRow($rowNumber, $raw, $mapped, duplicate: $duplicate);
        } catch (Throwable $exception) {
            return new ChunkRow($rowNumber, $raw, null, $exception, $duplicate);
        }
    }
}
