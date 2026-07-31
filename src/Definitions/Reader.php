<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Readers\CsvReader;
use ByRcsc\LaravelDataSync\Readers\FixedWidthReader;
use ByRcsc\LaravelDataSync\Readers\JsonReader;
use ByRcsc\LaravelDataSync\Readers\NdjsonReader;
use ByRcsc\LaravelDataSync\Readers\XlsxReader;

final class Reader
{
    public static function csv(): CsvReader
    {
        return new CsvReader;
    }

    public static function delimited(): CsvReader
    {
        return new CsvReader;
    }

    public static function json(): JsonReader
    {
        return new JsonReader;
    }

    public static function ndjson(): NdjsonReader
    {
        return new NdjsonReader;
    }

    public static function fixedWidth(): FixedWidthReader
    {
        return new FixedWidthReader;
    }

    public static function xlsx(): XlsxReader
    {
        return new XlsxReader;
    }
}
