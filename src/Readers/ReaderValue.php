<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use DateInterval;
use DateTimeInterface;

final class ReaderValue
{
    public static function normalize(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof DateInterval => $value->format('%rP%yY%mM%dDT%hH%iM%sS'),
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
