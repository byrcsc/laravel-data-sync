<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

final class Filename
{
    /**
     * Replaces path separators, so a remote filename cannot add directories to
     * the paths the package writes.
     */
    public static function safe(string $filename): string
    {
        return str_replace(['/', '\\'], '-', $filename);
    }
}
