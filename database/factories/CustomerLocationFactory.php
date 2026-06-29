<?php

namespace Database\Factories;

use App\Modules\Customers\Enums\CustomerLocationType;
use App\Modules\Customers\Models\CustomerLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerLocation>
 */
class CustomerLocationFactory extends Factory
{
    protected $model = CustomerLocation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'customer_id' => SupermarketFactory::new(),
            'name' => fake()->company().' '.fake()->city(),
            'type' => fake()->randomElement(CustomerLocationType::cases())->value,
            'country' => 'RO',
            'county' => fake()->state(),
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
            'notes' => null,
        ];
    }
}
