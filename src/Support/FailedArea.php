<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

/**
 * Where a failed or cancelled run's payload is kept, from `data-sync.failed`.
 * Without a configured disk it shares the staging disk.
 */
final class FailedArea
{
    public static function disk(): string
    {
        $configured = config('data-sync.failed.disk');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : StagingArea::disk();
    }

    public static function root(): string
    {
        $configured = config('data-sync.failed.path', 'data-sync/failed');

        return is_string($configured) && trim($configured, '/') !== ''
            ? trim($configured, '/')
            : 'data-sync/failed';
    }
}
