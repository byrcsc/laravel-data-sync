<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class EloquentApiMetaProductsSync extends ApiMetaProductsSync
{
    /**
     * Declaring a row hook moves the definition onto the Eloquent strategy.
     */
    public function afterSave(Model $model, array $row): void {}
}
