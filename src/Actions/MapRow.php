<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\DefinitionValidator;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Exceptions\SchemaMismatchException;

final class MapRow
{
    public function __construct(
        private readonly DefinitionValidator $validator,
    ) {}

    /**
     * @param  list<string|int>  $header
     * @return list<string|int>
     */
    public function validateSchema(SyncDefinition $definition, array $header): array
    {
        // The package writes an API snapshot itself, so one with no columns is a
        // feed that went quiet rather than a file missing its header.
        if ($header === [] && $definition instanceof ApiSyncDefinition) {
            return [];
        }

        $fields = $this->validator->fields($definition);
        $missing = [];

        foreach ($fields as $field) {
            if (in_array($field->source(), $header, true)) {
                continue;
            }

            if ($field->isOptional() || $field->hasDefault()) {
                continue;
            }

            $missing[] = $field->source();
        }

        if ($missing !== []) {
            throw SchemaMismatchException::missingColumns(
                $definition::class,
                $missing,
                $header,
            );
        }

        $known = array_map(
            fn (Field $field): string|int => $field->source(),
            $fields,
        );
        $unknown = array_values(array_filter(
            $header,
            fn (string|int $column): bool => ! in_array($column, $known, true),
        ));

        if ($unknown !== [] && $definition->onUnknownColumns() === UnknownColumnPolicy::Strict) {
            throw SchemaMismatchException::unknownColumns(
                $definition::class,
                $unknown,
                $header,
            );
        }

        return $unknown;
    }

    /**
     * @param  array<string|int, string|null>  $raw
     * @param  list<string|int>  $unknown
     * @return array<string, mixed>
     */
    public function __invoke(
        SyncDefinition $definition,
        array $raw,
        array $unknown,
    ): array {
        $mapped = [];

        foreach ($this->validator->fields($definition) as $field) {
            if (! array_key_exists($field->source(), $raw)) {
                $mapped[$field->name()] = $field->hasDefault()
                    ? $field->defaultValue()
                    : null;

                continue;
            }

            $mapped[$field->name()] = $field->apply($raw[$field->source()]);
        }

        if ($definition->onUnknownColumns() !== UnknownColumnPolicy::Capture) {
            return $mapped;
        }

        $unmapped = [];

        foreach ($unknown as $column) {
            $unmapped[(string) $column] = $raw[$column] ?? null;
        }

        $target = $definition->captureUnmappedInto();

        if ($target !== null) {
            $mapped[$target] = $unmapped;
        }

        return $mapped;
    }
}
