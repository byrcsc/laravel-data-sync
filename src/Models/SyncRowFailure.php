<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Models;

use ByRcsc\LaravelDataSync\Support\TableNames;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sync_run_id
 * @property int $row_number
 * @property array<string|int, mixed>|null $payload
 * @property array<string, list<string>> $errors
 * @property string|null $exception
 */
final class SyncRowFailure extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::rowFailures();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'payload' => 'array',
            'errors' => 'array',
        ];
    }
}
