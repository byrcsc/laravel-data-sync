<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\Field;

class ApiMetaProductsSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'api-meta-products';
    }

    public function fields(): array
    {
        return [
            ...parent::fields(),
            Field::make('meta', from: 'META')->optional(),
        ];
    }
}
