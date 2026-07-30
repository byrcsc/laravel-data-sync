<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;
use Illuminate\Database\Eloquent\Model;

final readonly class TransferDefinitionValidator
{
    /**
     * @return list<Field>
     */
    public function validate(TransferDefinition $definition): array
    {
        $model = $definition->model();
        $configured = $definition->fields();
        $matchOn = $definition->matchOn();

        if ($model === null && $configured === [] && $matchOn === []) {
            return [];
        }

        if ($model === null || $configured === [] || $matchOn === []) {
            throw InvalidDefinitionException::transferModelLinkIncomplete($definition::class);
        }

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw InvalidDefinitionException::modelDoesNotExist($definition::class, $model);
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

        foreach ($matchOn as $field) {
            if (! in_array($field, $names, true)) {
                throw InvalidDefinitionException::missingMatchField($definition::class, $field);
            }
        }

        return $fields;
    }
}
