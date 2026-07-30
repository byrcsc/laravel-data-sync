<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class Receipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
