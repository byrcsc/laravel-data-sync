<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkResult;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\WriteMode;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class ProcessChunkInline
{
    public function __construct(
        private readonly Factory $validation,
    ) {}

    /**
     * @param  list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>  $rows
     */
    public function __invoke(
        SyncDefinition $definition,
        SyncRun $run,
        WritePath $writePath,
        array $rows,
    ): ChunkResult {
        $modelClass = $definition->model();
        $connection = (new $modelClass)->getConnection();

        return $connection->transaction(
            fn (): ChunkResult => $this->process(
                $definition,
                $run,
                $writePath,
                $rows,
            ),
        );
    }

    /**
     * @param  list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>  $rows
     */
    private function process(
        SyncDefinition $definition,
        SyncRun $run,
        WritePath $writePath,
        array $rows,
    ): ChunkResult {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $bulkRows = [];
        $bulkModes = [];

        foreach ($rows as $item) {
            if ($item['duplicate']) {
                $skipped++;

                continue;
            }

            if ($item['exception'] instanceof Throwable || $item['mapped'] === null) {
                $this->recordFailure(
                    $definition,
                    $run,
                    $item['row_number'],
                    $item['raw'],
                    ['row' => [$item['exception']?->getMessage() ?? 'The row could not be mapped.']],
                    $item['exception'],
                );
                $failed++;

                continue;
            }

            $row = $item['mapped'];
            $validator = $this->validation->make($row, $definition->rules());
            $definition->withValidator($validator);

            if ($validator->fails()) {
                $errors = array_map(
                    fn (array $messages): array => array_values($messages),
                    $validator->errors()->toArray(),
                );
                $this->recordFailure(
                    $definition,
                    $run,
                    $item['row_number'],
                    $item['raw'],
                    $errors,
                );
                $failed++;

                continue;
            }

            if ($writePath === WritePath::Eloquent) {
                $outcome = $this->writeWithEloquent($definition, $row);

                $created += $outcome === 'created' ? 1 : 0;
                $updated += $outcome === 'updated' ? 1 : 0;
                $skipped += $outcome === 'skipped' ? 1 : 0;

                continue;
            }

            $classification = $this->classify($definition, $row);

            if ($classification === 'skipped') {
                $skipped++;

                continue;
            }

            $bulkRows[] = $this->databaseAttributes($definition, $row);
            $bulkModes[] = $classification;
        }

        if ($bulkRows !== []) {
            $this->writeBulk($definition, $bulkRows);
            $created += count(array_filter($bulkModes, fn (string $mode): bool => $mode === 'created'));
            $updated += count(array_filter($bulkModes, fn (string $mode): bool => $mode === 'updated'));
        }

        return new ChunkResult(
            total: count($rows),
            created: $created,
            updated: $updated,
            skipped: $skipped,
            failed: $failed,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeWithEloquent(SyncDefinition $definition, array $row): string
    {
        $modelClass = $definition->model();
        $identity = $this->identityAttributes($definition, $row);
        /** @var Model $model */
        $model = $modelClass::query()->firstOrNew($identity);

        if ($model->exists && $definition->writeMode() === WriteMode::CreateOnly) {
            return 'skipped';
        }

        if (! $model->exists && $definition->writeMode() === WriteMode::UpdateOnly) {
            return 'skipped';
        }

        $outcome = $model->exists ? 'updated' : 'created';
        $model->forceFill($row);

        if ($definition->beforeSave($model, $row) === false) {
            return 'skipped';
        }

        $model->save();
        $definition->afterSave($model, $row);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function classify(SyncDefinition $definition, array $row): string
    {
        $modelClass = $definition->model();
        $exists = $modelClass::query()
            ->where($this->identityAttributes($definition, $row))
            ->exists();

        if ($exists && $definition->writeMode() === WriteMode::CreateOnly) {
            return 'skipped';
        }

        if (! $exists && $definition->writeMode() === WriteMode::UpdateOnly) {
            return 'skipped';
        }

        return $exists ? 'updated' : 'created';
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function databaseAttributes(SyncDefinition $definition, array $row): array
    {
        $modelClass = $definition->model();
        /** @var Model $model */
        $model = new $modelClass;
        $model->forceFill($row);

        if ($model->usesTimestamps()) {
            $timestamp = $model->freshTimestampString();
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if ($createdAt !== null && $createdAt !== '') {
                $model->setAttribute($createdAt, $timestamp);
            }

            if ($updatedAt !== null && $updatedAt !== '') {
                $model->setAttribute($updatedAt, $timestamp);
            }
        }

        return $model->getAttributes();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function identityAttributes(SyncDefinition $definition, array $row): array
    {
        $identity = [];

        foreach ($definition->matchOn() as $attribute) {
            $identity[$attribute] = $row[$attribute] ?? null;
        }

        return $identity;
    }

    /**
     * @param  array<string|int, string|null>  $payload
     * @param  array<string, list<string>>  $errors
     */
    private function recordFailure(
        SyncDefinition $definition,
        SyncRun $run,
        int $rowNumber,
        array $payload,
        array $errors,
        ?Throwable $exception = null,
    ): void {
        SyncRowFailure::query()->create([
            'sync_run_id' => $run->getKey(),
            'row_number' => $rowNumber,
            'payload' => $definition->captureFailedPayloads() ? $payload : null,
            'errors' => $errors,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
