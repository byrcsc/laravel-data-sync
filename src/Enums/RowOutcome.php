<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum RowOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
}
