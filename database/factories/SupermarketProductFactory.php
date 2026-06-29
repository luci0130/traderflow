<?php

namespace Database\Factories;

use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupermarketProduct>
 */
class SupermarketProductFactory extends Factory
{
    protected $model = SupermarketProduct::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'brand' => fake()->company(),
            'category' => fake()->randomElement(['Lactate', 'Bauturi', 'Panificatie', 'Legume', 'Fructe']),
            'origin' => fake()->optional()->country(),
            'caliber' => fake()->optional()->randomElement(['40-50mm', '60-70mm', 'Class I', 'Class II']),
            'quality' => fake()->optional()->randomElement(['A', 'B', 'Premium', 'Standard']),
            'barcode' => fake()->optional()->ean13(),
            'packaging_method_id' => null,
            'package_size' => fake()->randomElement([0.5, 1, 1.5, 2]),
            'package_unit' => fake()->randomElement(['kg', 'l', 'buc']),
            'vat_rate' => SupermarketProduct::DEFAULT_VAT_RATE,
        ];
    }
}
