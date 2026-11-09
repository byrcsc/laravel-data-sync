<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

use Throwable;

final class ApiFetchException extends DataSyncException
{
    public static function fetchFailed(string $definition, string $endpoint, Throwable $previous): self
    {
        return new self(
            "[{$definition}] could not fetch [{$endpoint}]: {$previous->getMessage()}",
            previous: $previous,
        );
    }

    public static function pagingLoop(string $definition, string $url): self
    {
        return new self(
            "[{$definition}] was sent back to [{$url}], which it had already fetched. Check that nextPage() returns null on the last page.",
        );
    }
}
