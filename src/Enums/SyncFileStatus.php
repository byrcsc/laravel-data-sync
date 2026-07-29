<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum SyncFileStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
