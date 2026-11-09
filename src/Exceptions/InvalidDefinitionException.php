<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Exceptions;

final class InvalidDefinitionException extends DataSyncException
{
    public static function unknownName(string $name): self
    {
        return new self("No sync definition named [{$name}] is registered in [data-sync.syncs].");
    }

    public static function modelDoesNotExist(string $definition, string $model): self
    {
        return new self("[{$definition}] model() returned [{$model}], which is not an Eloquent model class.");
    }

    public static function emptyFields(string $definition): self
    {
        return new self("[{$definition}] fields() must return at least one Field.");
    }

    public static function invalidField(string $definition, int|string $key): self
    {
        return new self("[{$definition}] fields() contains an invalid Field at key [{$key}].");
    }

    public static function duplicateField(string $definition, string $field): self
    {
        return new self("[{$definition}] fields() maps [{$field}] more than once.");
    }

    public static function missingMatchField(string $definition, string $field): self
    {
        return new self("[{$definition}] matchOn() references [{$field}], but fields() does not map it.");
    }

    public static function emptyMatchOn(string $definition): self
    {
        return new self("[{$definition}] matchOn() must return at least one mapped field.");
    }

    public static function unknownModelAttribute(string $definition, string $attribute): self
    {
        return new self("[{$definition}] fields() maps [{$attribute}], which is not a column or writable attribute on the model returned by model().");
    }

    public static function missingCaptureTarget(string $definition): self
    {
        return new self("[{$definition}] uses the capture unknown-column policy, so captureUnmappedInto() must return a model attribute.");
    }

    public static function invalidCaptureCast(string $definition, string $attribute): self
    {
        return new self("[{$definition}] captureUnmappedInto() targets [{$attribute}], which must have an array-compatible Eloquent cast.");
    }

    public static function invalidChunkSize(string $definition): self
    {
        return new self("[{$definition}] chunkSize() must return an integer greater than zero.");
    }

    public static function invalidMaxErrors(string $definition): self
    {
        return new self("[{$definition}] maxErrors() must return null or a non-negative integer.");
    }

    public static function invalidEndpoint(string $definition, string $endpoint): self
    {
        return new self("[{$definition}] endpoint() returned [{$endpoint}], which is not an http or https URL.");
    }

    public static function invalidTimeout(string $definition): self
    {
        return new self("[{$definition}] timeout() must return a number of seconds greater than zero.");
    }

    public static function invalidRetries(string $definition): self
    {
        return new self("[{$definition}] retries() must return a non-negative number of extra attempts after the first.");
    }

    public static function invalidRetryDelay(string $definition): self
    {
        return new self("[{$definition}] retryDelay() must return a non-negative number of milliseconds.");
    }

    public static function invalidLockTimeout(string $definition): self
    {
        return new self("[{$definition}] lockTimeout() must return a number of seconds greater than zero.");
    }

    public static function transferModelLinkIncomplete(string $definition): self
    {
        return new self("[{$definition}] must return a model, at least one Field, and at least one matchOn field when using a transfer model link.");
    }
}
