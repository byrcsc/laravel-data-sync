<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncWatermark;

/**
 * Per-definition watermark state.
 *
 * A fetch records the value it computed on its own run; only a run that lands
 * in completed advances the watermark, so a failed or partial run leaves the next
 * fetch pointed at the same window.
 */
final class TrackWatermark
{
    public function current(string $definition): mixed
    {
        return SyncWatermark::query()
            ->where('definition', $definition)
            ->first()
            ?->value;
    }

    /**
     * Moves the watermark to the value a snapshot carries, and consumes it so the
     * snapshot cannot move the watermark a second time. Reprocessing an older
     * snapshot — through `sync:retry`, or with `--force` — therefore leaves the
     * watermark where the newest completed window put it.
     */
    public function commitFor(Definition $definition, SyncFile $file): void
    {
        if (! $definition instanceof ApiSyncDefinition) {
            return;
        }

        $watermark = $file->pending_watermark;

        if ($watermark === null) {
            return;
        }

        $this->advance($definition->name(), $watermark);
        $file->forceFill(['pending_watermark' => null])->save();
    }

    /**
     * Advancing is a single upsert: the unique index on definition decides the
     * winner when two runs of the same definition finish together, and either
     * winner is safe because a watermark that moves backwards only refetches.
     */
    public function advance(string $definition, mixed $value): void
    {
        $now = now();

        SyncWatermark::query()->upsert(
            [[
                'definition' => $definition,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'advanced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['definition'],
            ['value', 'advanced_at', 'updated_at'],
        );
    }
}
