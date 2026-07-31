<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Product;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /** @var class-string<Product> */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper($this->faker->unique()->bothify('??-###')),
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(100, 50_000),
            'category' => $this->faker->randomElement(['hardware', 'software', 'general']),
            'meta' => null,
        ];
    }
}
