<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum ExistingFilePolicy: string
{
    case Overwrite = 'overwrite';
    case Fail = 'fail';
}
