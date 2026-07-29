<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum WritePath: string
{
    case Bulk = 'bulk';
    case Eloquent = 'eloquent';
}
