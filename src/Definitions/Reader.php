<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Readers\CsvReader;

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
}
