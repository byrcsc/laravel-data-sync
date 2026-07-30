<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Jobs;

use ByRcsc\LaravelDataSync\Actions\ProcessChunkInline;
use ByRcsc\LaravelDataSync\Actions\ReadFileChunk;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Exceptions\MaxErrorsExceededException;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class ProcessChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string|int>  $unmappedColumns
     * @param  array<int, true>  $selectedRows
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $unmappedColumns,
        public readonly array $selectedRows,
        public readonly int $offset,
        public readonly int $limit,
    ) {}

    public function handle(
        SyncManager $manager,
        ReadFileChunk $readChunk,
        ProcessChunkInline $processChunk,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = SyncRun::query()->findOrFail($this->runId);
        $file = $run->file()->firstOrFail();
        $definition = $manager->definition((string) $run->definition);

        if (! $definition instanceof SyncDefinition) {
            throw new RuntimeException("Run [{$run->ulid}] is not a row sync.");
        }

        $writePath = $run->write_path;

        if (! $writePath instanceof WritePath) {
            throw new RuntimeException("Run [{$run->ulid}] has no row-sync write path.");
        }

        $rows = $readChunk(
            $definition,
            $file,
            $this->unmappedColumns,
            $this->selectedRows,
            $this->offset,
            $this->limit,
        );
        $result = $processChunk($definition, $run, $writePath, $rows);
        SyncRun::query()
            ->whereKey($run->getKey())
            ->incrementEach([
                'rows_total' => $result->total,
                'rows_created' => $result->created,
                'rows_updated' => $result->updated,
                'rows_skipped' => $result->skipped,
                'rows_failed' => $result->failed,
            ]);

        $run->refresh();
        $maximum = $definition->maxErrors();

        if ($maximum === null || $run->rows_failed === 0 || $run->rows_failed < $maximum) {
            return;
        }

        $this->batch()?->cancel();

        throw MaxErrorsExceededException::for($definition::class, $maximum);
    }
}
