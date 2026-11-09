<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Models\SyncRun;

final readonly class RunResult
{
    public function __construct(
        public SyncRun $run,
        public RunStatus $status,
        public ?WriteStrategy $writeStrategy,
        public int $total,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
    ) {}
}
