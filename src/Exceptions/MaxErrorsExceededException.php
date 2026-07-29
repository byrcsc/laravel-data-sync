<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

final class MaxErrorsExceededException extends DataSyncException
{
    public static function for(string $definition, int $maximum): self
    {
        return new self("[{$definition}] stopped after exceeding maxErrors() [{$maximum}].");
    }
}
