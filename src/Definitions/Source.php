<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use InvalidArgumentException;

final class Source
{
    private string $path = '';

    private int $minimumAgeSeconds = 0;

    private function __construct(
        private readonly ?string $disk,
        private readonly ?string $connection,
    ) {}

    public static function disk(string $disk): self
    {
        return new self($disk, null);
    }

    public static function connection(string $connection): self
    {
        return new self(null, $connection);
    }

    public function path(string $path): self
    {
        $this->path = trim($path, '/');

        return $this;
    }

    public function minFileAge(int $minutes = 0, int $seconds = 0): self
    {
        if ($minutes < 0 || $seconds < 0) {
            throw new InvalidArgumentException('Source minFileAge() values must be non-negative.');
        }

        $this->minimumAgeSeconds = ($minutes * 60) + $seconds;

        return $this;
    }

    public function diskName(): ?string
    {
        return $this->disk;
    }

    public function connectionName(): ?string
    {
        return $this->connection;
    }

    public function directory(): string
    {
        return $this->path;
    }

    public function minimumAgeSeconds(): int
    {
        return $this->minimumAgeSeconds;
    }
}
