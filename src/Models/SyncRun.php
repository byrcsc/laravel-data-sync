<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Models;

use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\WritePath;
use ByRcsc\LaravelDataSync\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $sync_file_id
 * @property string $definition
 * @property RunStatus $status
 * @property WritePath $write_path
 * @property bool $dry_run
 * @property string|null $batch_id
 * @property int $rows_total
 * @property int $rows_created
 * @property int $rows_updated
 * @property int $rows_skipped
 * @property int $rows_failed
 * @property list<string|int>|null $unmapped_columns
 * @property string|null $error_summary
 * @property list<string>|null $warnings
 * @property string|null $archive_path
 */
final class SyncRun extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::runs();
    }

    /** @return BelongsTo<SyncFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(SyncFile::class, 'sync_file_id');
    }

    /** @return HasMany<SyncRowFailure, $this> */
    public function failures(): HasMany
    {
        return $this->hasMany(SyncRowFailure::class, 'sync_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'write_path' => WritePath::class,
            'dry_run' => 'boolean',
            'rows_total' => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
            'unmapped_columns' => 'array',
            'warnings' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->ulid ??= (string) Str::ulid();
        });
    }
}
