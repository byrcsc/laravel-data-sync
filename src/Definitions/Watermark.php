<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

/**
 * How a database definition finds the rows that changed since its last
 * completed run. The package captures an upper bound before it reads, reads
 * only the rows between the stored bound and that one, and stores the new
 * bound when the run completes.
 */
final readonly class Watermark
{
    private function __construct(
        private string $mode,
        private string $column,
        private int $overlapSeconds,
    ) {}

    /**
     * A last-modified column. Each run also re-reads rows up to $overlapSeconds
     * below the stored bound, which catches a transaction that committed a
     * lower value after the previous read. The upsert on matchOn() makes the
     * re-read rows harmless.
     */
    public static function timestamp(string $column, int $overlapSeconds = 0): self
    {
        return new self('timestamp', $column, $overlapSeconds);
    }

    /**
     * A SQL Server `rowversion` column. The bound is MIN_ACTIVE_ROWVERSION(), so
     * a run reads committed rows only and needs no overlap.
     */
    public static function rowVersion(string $column): self
    {
        return new self('rowversion', $column, 0);
    }

    /**
     * Either `timestamp` or `rowversion`.
     */
    public function mode(): string
    {
        return $this->mode;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function overlapSeconds(): int
    {
        return $this->overlapSeconds;
    }
}
