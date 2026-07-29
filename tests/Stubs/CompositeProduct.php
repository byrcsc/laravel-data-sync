<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class CompositeProduct extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'composite_products';
}
