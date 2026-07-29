<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use Closure;

final class Field
{
    private bool $optional = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private ?Closure $transformer = null;

    private function __construct(
        private readonly string $name,
        private readonly string|int $source,
    ) {}

    public static function make(string $name, string|int|null $from = null): self
    {
        return new self($name, $from ?? $name);
    }

    public function transform(Closure $transformer): self
    {
        $this->transformer = $transformer;

        return $this;
    }

    public function optional(): self
    {
        $this->optional = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->optional = true;
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): string|int
    {
        return $this->source;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    public function apply(mixed $value): mixed
    {
        if ($this->transformer === null) {
            return $value;
        }

        return ($this->transformer)($value);
    }
}
