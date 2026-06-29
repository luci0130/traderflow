<?php

namespace Database\Factories;

use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupermarketPrice>
 */
class SupermarketPriceFactory extends Factory
{
    protected $model = SupermarketPrice::class;

    public function definition(): array
    {
        return [
            'supermarket_id' => SupermarketFactory::new(),
            'supermarket_product_id' => SupermarketProduct::factory(),
            'price' => fake()->randomFloat(2, 1, 100),
            'currency' => 'RON',
            'is_promo' => false,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_PHOTO,
        ];
    }
}
