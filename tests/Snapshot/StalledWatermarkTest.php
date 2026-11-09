<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Tests\Stubs\ApiProductsSync;
use ByRcsc\LaravelDataSync\Tests\Stubs\ProductsSync;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('data-sync.syncs', [ApiProductsSync::class, ProductsSync::class]);
    config()->set('data-sync.stalled_watermark_runs', 3);
});

/**
 * Records finished runs for a definition, oldest first.
 *
 * @param  list<RunStatus>  $statuses
 */
function finishedRuns(string $definition, array $statuses): void
{
    foreach ($statuses as $status) {
        $file = SyncFile::query()->create([
            'definition' => $definition,
            'checksum' => hash('sha256', (string) Str::ulid()),
            'path' => "{$definition}.ndjson",
            'filename' => "{$definition}.ndjson",
            'size' => 1,
            'mtime' => now()->timestamp,
            'status' => 'failed',
            'first_seen_at' => now(),
        ]);
        SyncRun::query()->create([
            'sync_file_id' => $file->id,
            'definition' => $definition,
            'status' => $status,
            'finished_at' => now(),
        ]);
    }
}

function stalledWarning(string $definition): string
{
    return "Definition [{$definition}] has not completed any of its last 3 runs";
}

it('flags a snapshot definition in status and doctor when its last runs all ended without completing', function (): void {
    finishedRuns('api-products', [
        RunStatus::Completed,
        RunStatus::Failed,
        RunStatus::CompletedWithErrors,
        RunStatus::CompletedWithErrors,
    ]);

    $this->artisan('sync:status')
        ->expectsOutputToContain(stalledWarning('api-products'))
        ->assertSuccessful();

    $this->artisan('sync:doctor')
        ->expectsOutputToContain(stalledWarning('api-products'));
});

it('clears the warning once one of the last runs completes', function (): void {
    finishedRuns('api-products', [
        RunStatus::Failed,
        RunStatus::Failed,
        RunStatus::Completed,
        RunStatus::Failed,
    ]);

    $this->artisan('sync:status')
        ->doesntExpectOutputToContain(stalledWarning('api-products'))
        ->assertSuccessful();
});

it('waits for the configured number of runs', function (): void {
    finishedRuns('api-products', [RunStatus::Failed, RunStatus::Failed]);

    $this->artisan('sync:status')
        ->doesntExpectOutputToContain(stalledWarning('api-products'))
        ->assertSuccessful();
});

it('never flags a file definition, which has no watermark', function (): void {
    finishedRuns('products', [RunStatus::Failed, RunStatus::Failed, RunStatus::Failed]);

    $this->artisan('sync:status')
        ->doesntExpectOutputToContain(stalledWarning('products'))
        ->assertSuccessful();

    $this->artisan('sync:doctor')
        ->doesntExpectOutputToContain(stalledWarning('products'));
});
