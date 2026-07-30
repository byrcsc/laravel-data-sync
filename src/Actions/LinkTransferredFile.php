<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinitionValidator;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final readonly class LinkTransferredFile
{
    public function __construct(
        private TransferDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return 'created'|'updated'|null
     */
    public function __invoke(TransferDefinition $definition, array $values): ?string
    {
        $fields = $this->validator->validate($definition);
        $modelClass = $definition->model();

        if ($modelClass === null) {
            return null;
        }

        $attributes = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->source(), $values)) {
                if ($field->isOptional() || $field->hasDefault()) {
                    $attributes[$field->name()] = $field->hasDefault()
                        ? $field->defaultValue()
                        : null;

                    continue;
                }

                throw new RuntimeException(
                    '['.$definition::class."] transfer field [{$field->name()}] references unavailable value [{$field->source()}].",
                );
            }

            $attributes[$field->name()] = $field->apply($values[$field->source()]);
        }

        $identity = [];

        foreach ($definition->matchOn() as $attribute) {
            $identity[$attribute] = $attributes[$attribute] ?? null;
        }

        $exists = $modelClass::query()->where($identity)->exists();
        /** @var Model $model */
        $model = new $modelClass;
        $model->forceFill($attributes);

        if ($model->usesTimestamps()) {
            $timestamp = $model->freshTimestampString();
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if ($createdAt !== null && $createdAt !== '') {
                $model->setAttribute($createdAt, $timestamp);
            }

            if ($updatedAt !== null && $updatedAt !== '') {
                $model->setAttribute($updatedAt, $timestamp);
            }
        }

        $database = $model->getAttributes();
        $updates = array_values(array_diff(array_keys($database), $definition->matchOn()));
        $modelClass::query()->upsert([$database], $definition->matchOn(), $updates);

        return $exists ? 'updated' : 'created';
    }
}
