<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Enums;

enum DuplicatePolicy: string
{
    case LastWins = 'last_wins';
    case FirstWins = 'first_wins';
    case Fail = 'fail';
}
