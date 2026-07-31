<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use Generator;
use InvalidArgumentException;
use RuntimeException;

final class FixedWidthReader implements Reader
{
    /**
     * @var array<string|int, array{start: int, length: int}>
     */
    private array $columns = [];

    public function column(
        string|int $name,
        int $start,
        int $length,
    ): self {
        if ($start < 0) {
            throw new InvalidArgumentException('Fixed-width column start must be zero or greater.');
        }

        if ($length < 1) {
            throw new InvalidArgumentException('Fixed-width column length must be greater than zero.');
        }

        $this->columns[$name] = [
            'start' => $start,
            'length' => $length,
        ];

        return $this;
    }

    public function header(mixed $stream): array
    {
        $this->assertStream($stream);

        return array_keys($this->columns);
    }

    public function rows(mixed $stream): Generator
    {
        $this->assertStream($stream);
        $lineNumber = 0;
        $requiredWidth = 0;

        foreach ($this->columns as $column) {
            $requiredWidth = max(
                $requiredWidth,
                $column['start'] + $column['length'],
            );
        }

        while (($line = fgets($stream)) !== false) {
            $lineNumber++;
            $line = rtrim($line, "\r\n");
            $width = strlen($line);

            if ($width < $requiredWidth) {
                throw new RuntimeException(
                    "Fixed-width line [{$lineNumber}] is {$width} bytes; configured columns require {$requiredWidth} bytes.",
                );
            }

            $row = [];

            foreach ($this->columns as $name => $column) {
                $row[$name] = trim(substr(
                    $line,
                    $column['start'],
                    $column['length'],
                ));
            }

            yield $lineNumber => $row;
        }
    }

    private function assertStream(mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Fixed-width reader expects a readable stream resource.');
        }
    }
}
