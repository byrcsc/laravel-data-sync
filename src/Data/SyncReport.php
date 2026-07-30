<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Models\SyncRun;

final readonly class SyncReport
{
    public function __construct(
        public SyncRun $run,
        public RunStatus $status,
        public ?WritePath $writePath,
        public int $total,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
    ) {}
}
