<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Events\SyncStarted;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\WriteStrategyResolver;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class StartRun
{
    public function __construct(
        private WriteStrategyResolver $writeStrategy,
        private Dispatcher $events,
    ) {}

    public function createPending(
        Definition $definition,
        SyncFile $file,
        bool $dryRun = false,
    ): SyncRun {
        return SyncRun::query()->create([
            'sync_file_id' => $file->getKey(),
            'definition' => $definition->name(),
            'status' => RunStatus::Pending,
            'write_strategy' => $definition instanceof SyncDefinition
                ? $this->writeStrategy->for($definition)
                : null,
            'dry_run' => $dryRun,
        ]);
    }

    public function start(
        Definition $definition,
        SyncFile $file,
        ?SyncRun $run = null,
        bool $dryRun = false,
    ): SyncRun {
        $run ??= $this->createPending($definition, $file, $dryRun);
        $run->forceFill([
            'status' => RunStatus::Running,
            'dry_run' => $dryRun,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        if (! $dryRun) {
            $file->forceFill(['status' => SyncFileStatus::Processing])->save();
        }

        $this->events->dispatch(new SyncStarted($run->fresh() ?? $run));

        return $run;
    }
}
