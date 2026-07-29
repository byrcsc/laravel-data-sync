<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

final class SchemaMismatchException extends DataSyncException
{
    /**
     * @param  list<string|int>  $header
     * @param  list<string|int>  $missing
     */
    public static function missingColumns(string $definition, array $missing, array $header): self
    {
        return new self(sprintf(
            '[%s] is missing required source columns [%s]. Actual header: [%s]. Mark a field optional() or provide default() if the column may be absent.',
            $definition,
            implode(', ', $missing),
            implode(', ', $header),
        ));
    }

    /**
     * @param  list<string|int>  $unknown
     * @param  list<string|int>  $header
     */
    public static function unknownColumns(string $definition, array $unknown, array $header): self
    {
        return new self(sprintf(
            '[%s] contains unknown source columns [%s]. Actual header: [%s].',
            $definition,
            implode(', ', $unknown),
            implode(', ', $header),
        ));
    }
}
