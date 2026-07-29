<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'products';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
