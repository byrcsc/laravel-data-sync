<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class RetryProductsSync extends ProductSyncDefinition
{
    public static bool $requireName = true;

    public static ?int $maximumErrors = null;

    public static bool $atomic = false;

    public function rules(): array
    {
        return [
            'sku' => ['required'],
            'name' => self::$requireName ? ['required'] : [],
            'price' => ['integer', 'min:0'],
        ];
    }

    public function maxErrors(): ?int
    {
        return self::$maximumErrors;
    }

    public function atomic(): bool
    {
        return self::$atomic;
    }
}
