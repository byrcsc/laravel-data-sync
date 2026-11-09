<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

/**
 * The attributes a definition's matchOn() names, which decide whether a row
 * creates a record or updates an existing one.
 */
final class RowIdentity
{
    /**
     * @param  list<string>  $matchOn
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function attributes(array $matchOn, array $row): array
    {
        $identity = [];

        foreach ($matchOn as $attribute) {
            $identity[$attribute] = $row[$attribute] ?? null;
        }

        return $identity;
    }

    /**
     * The identity as a string, for spotting two rows that target the same record.
     *
     * @param  list<string>  $matchOn
     * @param  array<string, mixed>  $row
     */
    public static function key(array $matchOn, array $row): string
    {
        return json_encode(self::attributes($matchOn, $row), JSON_THROW_ON_ERROR);
    }
}
