<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;

final class StatusCommand extends Command
{
    protected $signature = 'sync:status
        {name? : Limit history to one registered sync name}
        {--failures : Show failed-row details}
        {--limit=10 : Maximum number of runs to display}';

    protected $description = 'Show recent data sync runs and queued batch progress';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $name = $this->argument('name');
        $runs = SyncRun::query()
            ->when(
                is_string($name),
                fn (Builder $query): Builder => $query->where('definition', $name),
            )
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($runs->isEmpty()) {
            $this->components->info('No sync runs found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            $progress = "{$run->rows_total} rows";
            $writePath = $this->writePath($run);

            if ($run->batch_id !== null) {
                $batch = Bus::findBatch($run->batch_id);

                if ($batch !== null) {
                    $progress = "{$batch->progress()}% ({$batch->processedJobs()}/{$batch->totalJobs} jobs)";
                }
            }

            $rows[] = [
                $run->ulid,
                $run->definition,
                $run->status->value.($run->dry_run ? ' (dry run)' : '')
                    ." · {$writePath}",
                "{$run->rows_created}/{$run->rows_updated}/{$run->rows_skipped}/{$run->rows_failed}",
                $progress,
            ];
        }

        $this->table(
            ['Run', 'Sync', 'Status · path', 'C/U/S/F', 'Progress'],
            $rows,
        );

        foreach ($runs as $run) {
            $path = $this->writePath($run);
            $this->line("{$run->ulid} used the {$path} write path.");
        }

        if (! $this->option('failures')) {
            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            foreach ($run->failures()->orderBy('row_number')->get() as $failure) {
                $payload = $failure->payload === null
                    ? '[payload capture disabled]'
                    : json_encode($failure->payload, JSON_THROW_ON_ERROR);
                $errors = json_encode($failure->errors, JSON_THROW_ON_ERROR);
                $this->line("{$run->ulid} row {$failure->row_number}: {$errors} {$payload}");
            }
        }

        return self::SUCCESS;
    }

    private function writePath(SyncRun $run): string
    {
        $writePath = $run->write_path;

        return $writePath instanceof WritePath
            ? $writePath->value
            : 'transfer';
    }
}
