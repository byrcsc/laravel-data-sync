<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\ProductFactory;

final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // ProductsSync sets `captureUnmappedInto('meta')`, which the
            // definition validator only accepts against a JSON-ish cast.
            'meta' => 'array',
        ];
    }
}
