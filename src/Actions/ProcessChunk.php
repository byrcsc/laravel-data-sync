<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RowOutcome;
use ByRcsc\LaravelDataSync\Enums\WriteMode;
use ByRcsc\LaravelDataSync\Enums\WriteStrategy;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\Support\BulkWriteAttributes;
use ByRcsc\LaravelDataSync\Support\RowIdentity;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Validates and writes one chunk of rows in a single transaction, recording a
 * row failure for each row that does not map or does not validate.
 */
final readonly class ProcessChunk
{
    public function __construct(
        private Factory $validation,
    ) {}

    /**
     * @param  list<ChunkRow>  $rows
     */
    public function __invoke(
        SyncDefinition $definition,
        SyncRun $run,
        WriteStrategy $writeStrategy,
        array $rows,
        bool $dryRun = false,
    ): ChunkResult {
        $modelClass = $definition->model();
        $connection = (new $modelClass)->getConnection();

        return $connection->transaction(
            fn (): ChunkResult => $this->process(
                $definition,
                $run,
                $writeStrategy,
                $rows,
                $dryRun,
            ),
        );
    }

    /**
     * @param  list<ChunkRow>  $rows
     */
    private function process(
        SyncDefinition $definition,
        SyncRun $run,
        WriteStrategy $writeStrategy,
        array $rows,
        bool $dryRun,
    ): ChunkResult {
        $outcomes = [];
        $failed = 0;
        $bulkRows = [];

        foreach ($rows as $row) {
            if ($row->duplicate) {
                $outcomes[] = RowOutcome::Skipped;

                continue;
            }

            if ($row->exception instanceof Throwable || $row->mapped === null) {
                $this->recordFailure(
                    $definition,
                    $run,
                    $row,
                    ['row' => [$row->exception?->getMessage() ?? 'The row could not be mapped.']],
                );
                $failed++;

                continue;
            }

            $validator = $this->validation->make($row->mapped, $definition->rules());
            $definition->withValidator($validator);

            if ($validator->fails()) {
                $errors = array_map(
                    fn (array $messages): array => array_values($messages),
                    $validator->errors()->toArray(),
                );
                $this->recordFailure($definition, $run, $row, $errors);
                $failed++;

                continue;
            }

            if ($dryRun) {
                $outcomes[] = $this->predictOutcome($definition, $row->mapped);

                continue;
            }

            if ($writeStrategy === WriteStrategy::Eloquent) {
                $outcomes[] = $this->writeWithEloquent($definition, $row->mapped);

                continue;
            }

            $outcome = $this->predictOutcome($definition, $row->mapped);
            $outcomes[] = $outcome;

            if ($outcome !== RowOutcome::Skipped) {
                $bulkRows[] = BulkWriteAttributes::for($definition->model(), $row->mapped);
            }
        }

        if ($bulkRows !== []) {
            $this->writeBulk($definition, $bulkRows);
        }

        return new ChunkResult(
            total: count($rows),
            created: $this->count($outcomes, RowOutcome::Created),
            updated: $this->count($outcomes, RowOutcome::Updated),
            skipped: $this->count($outcomes, RowOutcome::Skipped),
            failed: $failed,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeWithEloquent(SyncDefinition $definition, array $row): RowOutcome
    {
        $modelClass = $definition->model();
        /** @var Model $model */
        $model = $modelClass::query()->firstOrNew(RowIdentity::attributes($definition->matchOn(), $row));

        if ($model->exists && $definition->writeMode() === WriteMode::CreateOnly) {
            return RowOutcome::Skipped;
        }

        if (! $model->exists && $definition->writeMode() === WriteMode::UpdateOnly) {
            return RowOutcome::Skipped;
        }

        $outcome = $model->exists ? RowOutcome::Updated : RowOutcome::Created;
        $model->forceFill($row);

        if ($definition->beforeSave($model, $row) === false) {
            return RowOutcome::Skipped;
        }

        $model->save();
        $definition->afterSave($model, $row);

        return $outcome;
    }

    /**
     * What a write would do to this row, from whether its identity already
     * exists and the definition's WriteMode. The bulk strategy and dry runs use
     * it, since neither saves through Eloquent.
     *
     * @param  array<string, mixed>  $row
     */
    private function predictOutcome(SyncDefinition $definition, array $row): RowOutcome
    {
        $modelClass = $definition->model();
        $exists = $modelClass::query()
            ->where(RowIdentity::attributes($definition->matchOn(), $row))
            ->exists();

        if ($exists && $definition->writeMode() === WriteMode::CreateOnly) {
            return RowOutcome::Skipped;
        }

        if (! $exists && $definition->writeMode() === WriteMode::UpdateOnly) {
            return RowOutcome::Skipped;
        }

        return $exists ? RowOutcome::Updated : RowOutcome::Created;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeBulk(SyncDefinition $definition, array $rows): void
    {
        $modelClass = $definition->model();
        $updates = array_values(array_diff(
            array_keys($rows[0]),
            $definition->matchOn(),
            [(new $modelClass)->getCreatedAtColumn()],
        ));

        if ($definition->writeMode() === WriteMode::CreateOnly || $updates === []) {
            $modelClass::query()->insertOrIgnore($rows);

            return;
        }

        $modelClass::query()->upsert(
            $rows,
            $definition->matchOn(),
            $updates,
        );
    }

    /**
     * @param  list<RowOutcome>  $outcomes
     */
    private function count(array $outcomes, RowOutcome $outcome): int
    {
        return count(array_filter($outcomes, fn (RowOutcome $each): bool => $each === $outcome));
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function recordFailure(
        SyncDefinition $definition,
        SyncRun $run,
        ChunkRow $row,
        array $errors,
    ): void {
        SyncRowFailure::query()->create([
            'sync_run_id' => $run->getKey(),
            'row_number' => $row->rowNumber,
            'payload' => $definition->captureFailedPayloads() ? $row->raw : null,
            'errors' => $errors,
            'exception' => $row->exception?->getMessage(),
        ]);
    }
}
