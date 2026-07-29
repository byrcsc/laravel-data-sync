<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final class Checksum
{
    public function for(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read [{$path}] while calculating its SHA-256 checksum.");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
