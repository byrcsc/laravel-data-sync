<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use Generator;
use JsonException;
use RuntimeException;
use stdClass;

final class NdjsonReader implements Reader
{
    public function header(mixed $stream): array
    {
        $header = [];

        foreach ($this->rows($stream) as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array($column, $header, true)) {
                    $header[] = $column;
                }
            }
        }

        return $header;
    }

    public function rows(mixed $stream): Generator
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('NDJSON reader expects a readable stream resource.');
        }

        $lineNumber = 0;

        while (($line = fgets($stream)) !== false) {
            $lineNumber++;

            if (trim($line) === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, false, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    "Could not decode NDJSON line [{$lineNumber}]: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            if (! $decoded instanceof stdClass) {
                throw new RuntimeException(
                    "NDJSON line [{$lineNumber}] must be a JSON object.",
                );
            }

            $row = [];

            foreach (get_object_vars($decoded) as $key => $value) {
                $row[$key] = ReaderValue::normalize($value);
            }

            yield $lineNumber => $row;
        }
    }
}
