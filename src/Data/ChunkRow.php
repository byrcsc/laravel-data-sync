<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Data;

use Throwable;

/**
 * One row read from a payload, ready for ProcessChunk to validate and write.
 *
 * A row that failed to map carries the exception and no mapped attributes. A
 * duplicate row lost duplicate resolution and is counted as skipped.
 */
final readonly class ChunkRow
{
    /**
     * @param  array<string|int, string|null>  $raw
     * @param  array<string, mixed>|null  $mapped
     */
    public function __construct(
        public int $rowNumber,
        public array $raw,
        public ?array $mapped,
        public ?Throwable $exception = null,
        public bool $duplicate = false,
    ) {}
}
