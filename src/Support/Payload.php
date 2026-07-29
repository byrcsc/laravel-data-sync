<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

final class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function asObject(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        return self::asObject($data[$key] ?? null);
    }
}
