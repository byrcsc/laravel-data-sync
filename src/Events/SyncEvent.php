<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Events;

use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class SyncEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SyncRun $run,
    ) {}
}
