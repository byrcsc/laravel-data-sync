<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Enums\WriteMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class SyncDefinition
{
    abstract public function source(): Source;

    abstract public function format(): Reader;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return array<int|string, mixed> */
    abstract public function fields(): array;

    /** @return list<string> */
    abstract public function matchOn(): array;

    public function name(): string
    {
        $basename = class_basename(static::class);
        $name = Str::endsWith($basename, 'Sync')
            ? Str::beforeLast($basename, 'Sync')
            : $basename;

        return Str::kebab($name);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function writeMode(): WriteMode
    {
        return WriteMode::Upsert;
    }

    public function onDuplicate(): DuplicatePolicy
    {
        return DuplicatePolicy::LastWins;
    }

    public function onUnknownColumns(): UnknownColumnPolicy
    {
        return UnknownColumnPolicy::Ignore;
    }

    public function captureUnmappedInto(): ?string
    {
        return null;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function maxErrors(): ?int
    {
        return null;
    }

    public function atomic(): bool
    {
        return false;
    }

    public function captureFailedPayloads(): bool
    {
        return (bool) config('data-sync.store_failed_payloads', true);
    }

    public function queueConnection(): ?string
    {
        return null;
    }

    public function queue(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function prepare(array $row): array
    {
        return $row;
    }

    public function withValidator(Validator $validator): void {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function beforeSave(Model $model, array $row): mixed
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function afterSave(Model $model, array $row): void {}
}
