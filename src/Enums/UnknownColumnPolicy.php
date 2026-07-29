<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum UnknownColumnPolicy: string
{
    case Strict = 'strict';
    case Ignore = 'ignore';
    case Capture = 'capture';
}
