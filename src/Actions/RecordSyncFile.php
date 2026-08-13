<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use Illuminate\Database\QueryException;

/**
 * The ledger entry for one payload, keyed on the definition and the checksum of
 * the contents. Discovery and API fetches share it so the identity rule and the
 * race handling have a single home.
 */
final class RecordSyncFile
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(string $definition, string $checksum, array $attributes): SyncFile
    {
        $file = SyncFile::query()
            ->where('definition', $definition)
            ->where('checksum', $checksum)
            ->first();

        if ($file instanceof SyncFile) {
            $file->forceFill($attributes)->save();

            return $file;
        }

        try {
            return SyncFile::query()->create([
                'definition' => $definition,
                'checksum' => $checksum,
                ...$attributes,
            ]);
        } catch (QueryException) {
            // The unique index on definition and checksum means a concurrent
            // writer won the race; join its row rather than fail the run.
            /** @var SyncFile $existing */
            $existing = SyncFile::query()
                ->where('definition', $definition)
                ->where('checksum', $checksum)
                ->firstOrFail();

            return $existing;
        }
    }

    public function completed(string $definition, string $checksum): ?SyncFile
    {
        $file = SyncFile::query()
            ->where('definition', $definition)
            ->where('checksum', $checksum)
            ->where('status', SyncFileStatus::Completed->value)
            ->first();

        return $file instanceof SyncFile ? $file : null;
    }
}
