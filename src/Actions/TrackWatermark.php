<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Models\SyncCursor;

/**
 * Per-definition watermark state.
 *
 * A fetch records the value it computed on its own run; only a run that lands
 * in completed advances the cursor, so a failed or partial run leaves the next
 * fetch pointed at the same window.
 */
final class TrackWatermark
{
    public function current(string $definition): mixed
    {
        return SyncCursor::query()
            ->where('definition', $definition)
            ->first()
            ?->value;
    }

    /**
     * Advancing is a single upsert: the unique index on definition decides the
     * winner when two runs of the same definition finish together, and either
     * winner is safe because a watermark that moves backwards only refetches.
     */
    public function advance(string $definition, mixed $value): void
    {
        $now = now();

        SyncCursor::query()->upsert(
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
