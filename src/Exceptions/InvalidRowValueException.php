<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

use JsonException;

final class InvalidRowValueException extends DataSyncException
{
    public static function invalidJson(string|int $column, string $attribute, JsonException $previous): self
    {
        return new self(
            "Column [{$column}] holds invalid JSON for the JSON-cast [{$attribute}] attribute: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
