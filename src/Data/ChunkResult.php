<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

final readonly class ChunkResult
{
    public function __construct(
        public int $total = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}
}
