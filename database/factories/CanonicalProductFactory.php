<?php

namespace Database\Factories;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanonicalProduct>
 */
class CanonicalProductFactory extends Factory
{
    protected $model = CanonicalProduct::class;

    public function definition(): array
    {
        return [
            'product_category_id' => null,
            'name' => fake()->words(2, true),
            'variety' => fake()->optional()->word(),
            'caliber' => fake()->optional()->randomElement(['40-50mm', '60-70mm', 'Class I', 'Class II']),
            'package_size' => fake()->optional()->randomElement([0.5, 1, 2, 5]),
            'package_unit' => fake()->randomElement(['kg', 'g', 'buc']),
        ];
    }
}
