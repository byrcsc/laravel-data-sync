<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Models;

use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $checksum
 * @property string $definition
 * @property string $path
 * @property string $filename
 * @property SyncFileStatus $status
 */
final class SyncFile extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::files();
    }

    /** @return HasMany<SyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'sync_file_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncFileStatus::class,
            'size' => 'integer',
            'mtime' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $file): void {
            $file->ulid ??= (string) Str::ulid();
        });
    }
}
