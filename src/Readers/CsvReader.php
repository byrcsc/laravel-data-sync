<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use Generator;
use InvalidArgumentException;
use RuntimeException;

final class CsvReader implements Reader
{
    private string $delimiter = ',';

    private string $enclosure = '"';

    private string $escape = '\\';

    private bool $usesHeader = true;

    private string $encoding = 'UTF-8';

    public function delimiter(string $delimiter): self
    {
        $this->assertSingleCharacter($delimiter, 'delimiter');
        $this->delimiter = $delimiter;

        return $this;
    }

    public function enclosure(string $enclosure): self
    {
        $this->assertSingleCharacter($enclosure, 'enclosure');
        $this->enclosure = $enclosure;

        return $this;
    }

    public function escape(string $escape): self
    {
        if ($escape !== '') {
            $this->assertSingleCharacter($escape, 'escape');
        }

        $this->escape = $escape;

        return $this;
    }

    public function withoutHeader(): self
    {
        $this->usesHeader = false;

        return $this;
    }

    public function withHeader(): self
    {
        $this->usesHeader = true;

        return $this;
    }

    public function encoding(string $encoding): self
    {
        if (trim($encoding) === '') {
            throw new InvalidArgumentException('CSV encoding must be a non-empty encoding name.');
        }

        $this->encoding = $encoding;

        return $this;
    }

    public function header(mixed $stream): array
    {
        $this->assertStream($stream);

        $firstRow = $this->readRow($stream);

        if ($firstRow === null) {
            return [];
        }

        if (! $this->usesHeader) {
            return array_keys($firstRow);
        }

        $firstRow[0] = $this->stripBom($firstRow[0] ?? '');

        return array_map(
            fn (?string $heading): string => $heading ?? '',
            $firstRow,
        );
    }

    public function rows(mixed $stream): Generator
    {
        $this->assertStream($stream);

        $header = null;
        $rowNumber = 0;

        if ($this->usesHeader) {
            $header = $this->readRow($stream);
            $rowNumber = 1;

            if ($header === null) {
                return;
            }

            $header[0] = $this->stripBom($header[0] ?? '');
        }

        while (($values = $this->readRow($stream)) !== null) {
            $rowNumber++;

            if (! $this->usesHeader) {
                if ($rowNumber === 1) {
                    $values[0] = $this->stripBom($values[0] ?? '');
                }

                yield $rowNumber => $values;

                continue;
            }

            $row = [];

            foreach ($header as $index => $heading) {
                $row[$heading] = $values[$index] ?? null;
            }

            yield $rowNumber => $row;
        }
    }

    /**
     * @param  resource  $stream
     * @return list<string|null>|null
     */
    private function readRow(mixed $stream): ?array
    {
        $row = fgetcsv(
            stream: $stream,
            separator: $this->delimiter,
            enclosure: $this->enclosure,
            escape: $this->escape,
        );

        if ($row === false) {
            return null;
        }

        return array_map($this->convert(...), $row);
    }

    private function convert(?string $value): ?string
    {
        if ($value === null || strcasecmp($this->encoding, 'UTF-8') === 0) {
            return $value;
        }

        $converted = iconv($this->encoding, 'UTF-8//IGNORE', $value);

        if ($converted === false) {
            throw new RuntimeException("Could not convert CSV input from [{$this->encoding}] to UTF-8.");
        }

        return $converted;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF")
            ? substr($value, 3)
            : $value;
    }

    private function assertSingleCharacter(string $value, string $option): void
    {
        if (strlen($value) !== 1) {
            throw new InvalidArgumentException("CSV {$option} must be exactly one byte.");
        }
    }

    private function assertStream(mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('CSV reader expects a readable stream resource.');
        }
    }
}
