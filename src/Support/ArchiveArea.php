<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

/**
 * Where a completed run's payload is copied, from `data-sync.archive`.
 */
final class ArchiveArea
{
    public static function disk(): string
    {
        $configured = config('data-sync.archive.disk', 'local');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'local';
    }

    public static function root(): string
    {
        $configured = config('data-sync.archive.path', 'data-sync/archive');

        return is_string($configured) && trim($configured, '/') !== ''
            ? trim($configured, '/')
            : 'data-sync/archive';
    }
}
