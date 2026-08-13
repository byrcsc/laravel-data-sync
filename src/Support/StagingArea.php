<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

final class StagingArea
{
    public static function disk(): string
    {
        $configured = config('data-sync.staging.disk', 'local');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'local';
    }

    public static function root(): string
    {
        $configured = config('data-sync.staging.path', 'data-sync/staging');

        if (! is_string($configured) || trim($configured, '/') === '') {
            return 'data-sync/staging';
        }

        return trim($configured, '/');
    }

    public static function directoryFor(string $definition): string
    {
        return self::root().'/'.$definition;
    }
}
