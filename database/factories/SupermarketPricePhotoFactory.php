<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupermarketPricePhoto>
 */
class SupermarketPricePhotoFactory extends Factory
{
    protected $model = SupermarketPricePhoto::class;

    public function definition(): array
    {
        return [
            'supermarket_id' => SupermarketFactory::new(),
            'customer_location_id' => null,
            'uploaded_by' => User::factory(),
            'path' => 'supermarket-photos/'.fake()->uuid().'.jpg',
            'store_label' => fake()->city(),
            'taken_at' => today(),
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ];
    }

    public function done(): static
    {
        return $this->state(['status' => SupermarketPricePhoto::STATUS_DONE]);
    }
}
