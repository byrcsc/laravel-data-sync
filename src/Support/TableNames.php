<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

final class TableNames
{
    public static function files(): string
    {
        return self::resolve('files', 'sync_files');
    }

    public static function runs(): string
    {
        return self::resolve('runs', 'sync_runs');
    }

    public static function rowFailures(): string
    {
        return self::resolve('row_failures', 'sync_row_failures');
    }

    public static function cursors(): string
    {
        return self::resolve('cursors', 'sync_cursors');
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return ['files', 'runs', 'row_failures', 'cursors'];
    }

    private static function resolve(string $key, string $default): string
    {
        $configured = config("data-sync.tables.{$key}", $default);

        if (! is_string($configured)) {
            return $default;
        }

        if (trim($configured) === '') {
            return $default;
        }

        return $configured;
    }
}
