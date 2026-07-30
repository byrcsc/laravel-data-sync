<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

final readonly class PruneReport
{
    public function __construct(
        public int $runs = 0,
        public int $rowFailures = 0,
        public int $archives = 0,
        public int $failedFiles = 0,
    ) {}
}
