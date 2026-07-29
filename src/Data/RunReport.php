<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

final readonly class RunReport
{
    /**
     * @param  list<SyncReport>  $runs
     */
    public function __construct(
        public string $definition,
        public array $runs,
        public int $discovered,
        public int $skipped,
        public int $deferred,
        public bool $locked = false,
    ) {}
}
