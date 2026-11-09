<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinitionValidator;
use ByRcsc\LaravelDataSync\Support\BulkWriteAttributes;
use RuntimeException;

final readonly class LinkTransferredFile
{
    public function __construct(
        private TransferDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function __invoke(TransferDefinition $definition, array $values): void
    {
        $fields = $this->validator->validate($definition);
        $modelClass = $definition->model();

        if ($modelClass === null) {
            return;
        }

        $attributes = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->from(), $values)) {
                if ($field->isOptional() || $field->hasDefault()) {
                    $attributes[$field->name()] = $field->hasDefault()
                        ? $field->defaultValue()
                        : null;

                    continue;
                }

                throw new RuntimeException(
                    '['.$definition::class."] transfer field [{$field->name()}] references unavailable value [{$field->from()}].",
                );
            }

            $attributes[$field->name()] = $field->apply($values[$field->from()]);
        }

        $database = BulkWriteAttributes::for($modelClass, $attributes);
        $updates = array_values(array_diff(array_keys($database), $definition->matchOn()));
        $modelClass::query()->upsert([$database], $definition->matchOn(), $updates);
    }
}
