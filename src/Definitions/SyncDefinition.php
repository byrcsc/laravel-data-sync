<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Enums\UnknownColumnPolicy;
use ByRcsc\LaravelDataSync\Enums\WriteMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;

abstract class SyncDefinition extends Definition
{
    private bool $failedPayloadCaptureEnabled = true;

    abstract public function format(): Reader;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return array<int|string, mixed> */
    abstract public function fields(): array;

    /** @return list<string> */
    abstract public function matchOn(): array;

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
        return $this->failedPayloadCaptureEnabled
            && (bool) config('data-sync.store_failed_payloads', true);
    }

    public function withoutPayloadCapture(): static
    {
        $this->failedPayloadCaptureEnabled = false;

        return $this;
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
