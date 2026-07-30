<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Readers;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use Generator;
use JsonException;
use RuntimeException;

final class JsonReader implements Reader
{
    private ?string $rowPath = null;

    public function path(string $path): self
    {
        $this->rowPath = trim($path, '.');

        return $this;
    }

    public function header(mixed $stream): array
    {
        $rows = $this->readRows($stream);
        $header = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("JSON row [{$index}] must be an object.");
            }

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
        foreach (array_values($this->readRows($stream)) as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException(
                    "JSON row [{$index}] must be an object.",
                );
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                $normalized[$key] = ReaderValue::normalize($value);
            }

            yield $index + 1 => $normalized;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readRows(mixed $stream): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('JSON reader expects a readable stream resource.');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new RuntimeException('Could not read the JSON stream.');
        }

        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Could not decode the JSON document: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $rows = $document;

        if ($this->rowPath !== null && $this->rowPath !== '') {
            foreach (explode('.', $this->rowPath) as $segment) {
                if (! is_array($rows) || ! array_key_exists($segment, $rows)) {
                    throw new RuntimeException(
                        "JSON row path [{$this->rowPath}] does not exist.",
                    );
                }

                $rows = $rows[$segment];
            }
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            $path = $this->rowPath ?? '[document root]';

            throw new RuntimeException(
                "JSON row path [{$path}] must contain an array of row objects.",
            );
        }

        return $rows;
    }
}
