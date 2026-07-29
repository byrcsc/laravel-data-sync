<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

use ByRcsc\LaravelDataSync\Models\SyncFile;

final readonly class DiscoveryResult
{
    /**
     * @param  list<SyncFile>  $files
     */
    public function __construct(
        public array $files,
        public int $skipped,
        public int $deferred,
        public bool $locked = false,
    ) {}
}
