<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use Illuminate\Database\Eloquent\Model;

final class DefinitionValidator
{
    public function __construct(
        private readonly ApiDefinitionValidator $apiDefinitions,
        private readonly DatabaseDefinitionValidator $databaseDefinitions,
    ) {}

    public function validate(SyncDefinition $definition): void
    {
        $fields = $this->fields($definition);
        $model = $this->model($definition);

        $this->validateMatchOn($definition, $fields);
        $this->validateLimits($definition);

        if ($definition instanceof SnapshotSyncDefinition && $definition->lockTimeout() < 1) {
            throw InvalidDefinitionException::invalidLockTimeout($definition::class);
        }

        if ($definition instanceof ApiSyncDefinition) {
            $this->apiDefinitions->validate($definition);
        }

        if ($definition instanceof DatabaseSyncDefinition) {
            $this->databaseDefinitions->validate($definition);
        }

        $this->validateAttributes($definition, $model, $fields);
        $this->validateCaptureTarget($definition, $model);
    }

    /**
     * @return list<Field>
     */
    public function fields(SyncDefinition $definition): array
    {
        $configured = $definition->fields();

        if ($configured === []) {
            throw InvalidDefinitionException::emptyFields($definition::class);
        }

        $fields = [];
        $names = [];

        foreach ($configured as $key => $field) {
            if (! $field instanceof Field) {
                throw InvalidDefinitionException::invalidField($definition::class, $key);
            }

            if (in_array($field->name(), $names, true)) {
                throw InvalidDefinitionException::duplicateField($definition::class, $field->name());
            }

            $fields[] = $field;
            $names[] = $field->name();
        }

        return $fields;
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(SyncDefinition $definition): string
    {
        $model = $definition->model();

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw InvalidDefinitionException::modelDoesNotExist($definition::class, $model);
        }

        return $model;
    }

    private function model(SyncDefinition $definition): Model
    {
        $model = $this->modelClass($definition);

        return new $model;
    }

    /**
     * @param  list<Field>  $fields
     */
    private function validateMatchOn(SyncDefinition $definition, array $fields): void
    {
        $matchOn = $definition->matchOn();

        if ($matchOn === []) {
            throw InvalidDefinitionException::emptyMatchOn($definition::class);
        }

        $mapped = array_map(
            fn (Field $field): string => $field->name(),
            $fields,
        );

        foreach ($matchOn as $field) {
            if (! in_array($field, $mapped, true)) {
                throw InvalidDefinitionException::missingMatchField($definition::class, $field);
            }
        }
    }

    private function validateLimits(SyncDefinition $definition): void
    {
        if ($definition->chunkSize() < 1) {
            throw InvalidDefinitionException::invalidChunkSize($definition::class);
        }

        $maxErrors = $definition->maxErrors();

        if ($maxErrors !== null && $maxErrors < 0) {
            throw InvalidDefinitionException::invalidMaxErrors($definition::class);
        }
    }

    /**
     * @param  list<Field>  $fields
     */
    private function validateAttributes(
        SyncDefinition $definition,
        Model $model,
        array $fields,
    ): void {
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        if (! $schema->hasTable($table)) {
            return;
        }

        $columns = $schema->getColumnListing($table);

        foreach ($fields as $field) {
            if ($this->isWritableAttribute($model, $columns, $field->name())) {
                continue;
            }

            throw InvalidDefinitionException::unknownModelAttribute($definition::class, $field->name());
        }
    }

    private function validateCaptureTarget(SyncDefinition $definition, Model $model): void
    {
        if ($definition->onUnknownColumns() !== UnknownColumnPolicy::Capture) {
            return;
        }

        $target = $definition->captureUnmappedInto();

        if ($target === null || trim($target) === '') {
            throw InvalidDefinitionException::missingCaptureTarget($definition::class);
        }

        $compatibleCasts = [
            'array',
            'json',
            'object',
            'collection',
            'encrypted:array',
            'encrypted:collection',
        ];

        if (! $model->hasCast($target, $compatibleCasts)) {
            throw InvalidDefinitionException::invalidCaptureCast($definition::class, $target);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function isWritableAttribute(Model $model, array $columns, string $attribute): bool
    {
        if (in_array($attribute, $columns, true)) {
            return true;
        }

        if ($model->hasSetMutator($attribute)) {
            return true;
        }

        return $model->hasAttributeSetMutator($attribute);
    }
}
