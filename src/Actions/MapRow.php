<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Exceptions\InvalidRowValueException;
use ByRcsc\LaravelDataSync\Exceptions\SchemaMismatchException;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class MapRow
{
    /**
     * Casts that JSON-encode whatever they are given, so a JSON string bound
     * for one of them is stored encoded twice unless it is decoded first.
     */
    private const array JSON_CASTS = [
        'array',
        'json',
        'json:unicode',
        'object',
        'collection',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
    ];

    private const array JSON_CAST_CLASSES = [
        AsArrayObject::class,
        AsCollection::class,
        AsEncryptedArrayObject::class,
        AsEncryptedCollection::class,
    ];

    /** @var array<class-string<Model>, array<string, bool>> */
    private array $jsonCasts = [];

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

            $value = $field->apply($raw[$field->from()]);

            if (is_string($value) && $this->hasJsonCast($definition->model(), $field->name())) {
                $value = $this->decodeJson($value, $field);
            }

            $mapped[$field->name()] = $value;
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
     * Every reader hands a JSON column over as a string, and a JSON cast encodes
     * whatever it is given, so the string is decoded here and encoded once by
     * the cast. The check keys on the destination cast because some sources,
     * such as SQL Server before 2025, store JSON as plain text.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function hasJsonCast(string $modelClass, string $attribute): bool
    {
        if (! isset($this->jsonCasts[$modelClass])) {
            $casts = [];

            foreach ((new $modelClass)->getCasts() as $key => $cast) {
                $casts[$key] = in_array(strtolower($cast), self::JSON_CASTS, true)
                    || in_array(Str::before($cast, ':'), self::JSON_CAST_CLASSES, true);
            }

            $this->jsonCasts[$modelClass] = $casts;
        }

        return $this->jsonCasts[$modelClass][$attribute] ?? false;
    }

    private function decodeJson(string $value, Field $field): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidRowValueException::invalidJson($field->from(), $field->name(), $exception);
        }
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
