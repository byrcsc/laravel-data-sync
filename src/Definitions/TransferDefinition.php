<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Data\TransferredFile;
use ByRcsc\LaravelDataSync\Enums\UnmatchedFilenamePolicy;
use Illuminate\Database\Eloquent\Model;

abstract class TransferDefinition extends Definition
{
    abstract public function filenamePattern(): string;

    abstract public function destination(): Destination;

    public function onUnmatched(): UnmatchedFilenamePolicy
    {
        return UnmatchedFilenamePolicy::Skip;
    }

    /** @return class-string<Model>|null */
    public function model(): ?string
    {
        return null;
    }

    /** @return array<int|string, mixed> */
    public function fields(): array
    {
        return [];
    }

    /** @return list<string> */
    public function matchOn(): array
    {
        return [];
    }

    public function afterTransfer(TransferredFile $file): void {}
}
