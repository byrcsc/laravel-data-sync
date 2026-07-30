<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutReader;
use RuntimeException;

final class XlsxReader implements Reader
{
    private bool $usesHeader = true;

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

    public function header(mixed $stream): array
    {
        foreach ($this->rowValues($stream) as $values) {
            return $this->usesHeader
                ? array_map(
                    fn (?string $value): string => $value ?? '',
                    $values,
                )
                : array_keys($values);
        }

        return [];
    }

    public function rows(mixed $stream): Generator
    {
        $header = null;

        foreach ($this->rowValues($stream) as $rowNumber => $values) {
            if ($this->usesHeader && $header === null) {
                $header = array_map(
                    fn (?string $value): string => $value ?? '',
                    $values,
                );

                continue;
            }

            if (! $this->usesHeader) {
                yield $rowNumber => $values;

                continue;
            }

            $mapped = [];

            foreach ($header as $index => $heading) {
                $mapped[$heading] = $values[$index] ?? null;
            }

            yield $rowNumber => $mapped;
        }
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function rowValues(mixed $stream): Generator
    {
        [$temporary, $path] = $this->temporaryFile($stream);
        $reader = new OpenSpoutReader;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;

                    yield $rowNumber => $this->values($row);
                }

                break;
            }
        } finally {
            $reader->close();
            fclose($temporary);
        }
    }

    /**
     * @return list<string|null>
     */
    private function values(Row $row): array
    {
        return array_map(ReaderValue::normalize(...), $row->toArray());
    }

    /**
     * @return array{resource, string}
     */
    private function temporaryFile(mixed $stream): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('XLSX reader expects a readable stream resource.');
        }

        $temporary = tmpfile();

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for XLSX streaming.');
        }

        if (stream_copy_to_stream($stream, $temporary) === false) {
            fclose($temporary);

            throw new RuntimeException('Could not stage the XLSX stream for reading.');
        }

        fflush($temporary);
        $metadata = stream_get_meta_data($temporary);
        $path = $metadata['uri'] ?? null;

        if (! is_string($path)) {
            fclose($temporary);

            throw new RuntimeException('Could not resolve the temporary XLSX file path.');
        }

        return [$temporary, $path];
    }
}
