<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

final readonly class DoctorReport
{
    /**
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $failures,
        public array $warnings,
    ) {}
}
