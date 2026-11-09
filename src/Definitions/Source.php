<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use InvalidArgumentException;

final class Source
{
    private string $path = '';

    private int $minimumAgeSeconds = 0;

    private ?string $copyPath = null;

    private ?string $movePath = null;

    private bool $deleteOriginal = false;

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

    /**
     * After a run completes, copies the original file to this path on the same disk.
     */
    public function copyTo(string $path): self
    {
        $this->copyPath = trim($path, '/');

        return $this;
    }

    /**
     * After a run completes, moves the original file to this path on the same disk.
     */
    public function moveTo(string $path): self
    {
        $this->movePath = trim($path, '/');

        return $this;
    }

    /**
     * After a run completes, deletes the original file. moveTo() takes precedence.
     */
    public function deleteOriginal(bool $delete = true): self
    {
        $this->deleteOriginal = $delete;

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

    public function pathName(): string
    {
        return $this->path;
    }

    public function minimumAgeSeconds(): int
    {
        return $this->minimumAgeSeconds;
    }

    public function copyPath(): ?string
    {
        return $this->copyPath;
    }

    public function movePath(): ?string
    {
        return $this->movePath;
    }

    public function deletesOriginal(): bool
    {
        return $this->deleteOriginal;
    }
}
