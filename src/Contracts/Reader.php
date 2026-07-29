<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Contracts;

use Generator;

interface Reader
{
    /**
     * @param  resource  $stream
     * @return list<string|int>
     */
    public function header(mixed $stream): array;

    /**
     * @param  resource  $stream
     * @return Generator<int, array<string|int, string|null>>
     */
    public function rows(mixed $stream): Generator;
}
