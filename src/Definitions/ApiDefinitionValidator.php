<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Exceptions\InvalidDefinitionException;

final class ApiDefinitionValidator
{
    public function validate(ApiSyncDefinition $definition): void
    {
        $this->validateEndpoint($definition);
        $this->validateHttpOptions($definition);
    }

    private function validateEndpoint(ApiSyncDefinition $definition): void
    {
        $endpoint = trim($definition->endpoint());
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)) {
            throw InvalidDefinitionException::invalidEndpoint($definition::class, $endpoint);
        }
    }

    private function validateHttpOptions(ApiSyncDefinition $definition): void
    {
        if ($definition->timeout() < 1) {
            throw InvalidDefinitionException::invalidTimeout($definition::class);
        }

        if ($definition->retries() < 0) {
            throw InvalidDefinitionException::invalidRetries($definition::class);
        }

        if ($definition->retryDelay() < 0) {
            throw InvalidDefinitionException::invalidRetryDelay($definition::class);
        }
    }
}
