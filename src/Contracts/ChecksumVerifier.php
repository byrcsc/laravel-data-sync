<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Contracts;

use Illuminate\Contracts\Filesystem\Filesystem;

interface ChecksumVerifier
{
    public function for(Filesystem $disk, string $path): string;
}
