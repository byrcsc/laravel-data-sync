<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;

final readonly class TransferredFile
{
    /**
     * @param  array<string, mixed>  $segments
     */
    public function __construct(
        public SyncRun $run,
        public SyncFile $file,
        public string $destinationDisk,
        public string $destinationPath,
        public string $checksum,
        public array $segments,
        public bool $skipped,
    ) {}
}
