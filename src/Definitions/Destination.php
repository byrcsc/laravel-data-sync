<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Enums\ExistingFilePolicy;

final class Destination
{
    private string $path = '{filename}';

    private ExistingFilePolicy $existing = ExistingFilePolicy::Overwrite;

    private function __construct(
        private readonly string $disk,
    ) {}

    public static function disk(string $disk): self
    {
        return new self($disk);
    }

    public function path(string $path): self
    {
        $this->path = trim($path, '/');

        return $this;
    }

    public function onExisting(ExistingFilePolicy $policy): self
    {
        $this->existing = $policy;

        return $this;
    }

    public function diskName(): string
    {
        return $this->disk;
    }

    public function pathTemplate(): string
    {
        return $this->path;
    }

    public function existingPolicy(): ExistingFilePolicy
    {
        return $this->existing;
    }
}
