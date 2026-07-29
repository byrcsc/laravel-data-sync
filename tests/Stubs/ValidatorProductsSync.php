<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Contracts\Validation\Validator;

final class ValidatorProductsSync extends ProductSyncDefinition
{
    public function name(): string
    {
        return 'validator-products';
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->getData()['name'] === 'Forbidden') {
                $validator->errors()->add('name', 'This product name is forbidden.');
            }
        });
    }
}
