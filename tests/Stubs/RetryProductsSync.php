<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

final class RetryProductsSync extends ProductSyncDefinition
{
    public static bool $requireName = true;

    public function rules(): array
    {
        return [
            'sku' => ['required'],
            'name' => self::$requireName ? ['required'] : [],
            'price' => ['integer', 'min:0'],
        ];
    }
}
