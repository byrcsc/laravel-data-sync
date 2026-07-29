<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum WriteMode: string
{
    case Upsert = 'upsert';
    case CreateOnly = 'create_only';
    case UpdateOnly = 'update_only';
}
