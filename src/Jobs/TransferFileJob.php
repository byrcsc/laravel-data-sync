<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Jobs;

use ByRcsc\LaravelDataSync\Actions\TransferFile;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class TransferFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function handle(SyncManager $manager, TransferFile $transferFile): void
    {
        $run = SyncRun::query()->findOrFail($this->runId);
        $file = $run->file()->firstOrFail();
        $definition = $manager->definition((string) $run->definition);

        if (! $definition instanceof TransferDefinition) {
            throw new RuntimeException("Run [{$run->ulid}] is not a file transfer.");
        }

        $transferFile($definition, $file, $run);
    }
}
