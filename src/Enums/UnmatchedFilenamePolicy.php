<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum UnmatchedFilenamePolicy: string
{
    case Skip = 'skip';
    case Fail = 'fail';
}
