<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

final readonly class FilePlan
{
    /**
     * @param  list<string|int>  $unmappedColumns
     * @param  array<int, true>  $selectedRows
     * @param  list<array{offset: int, limit: int}>  $chunks
     */
    public function __construct(
        public array $unmappedColumns,
        public array $selectedRows,
        public array $chunks,
        public int $rowsTotal,
    ) {}
}
