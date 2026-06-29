<?php

namespace Database\Factories;

use App\Modules\Products\Models\PackagingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackagingMethod>
 */
class PackagingMethodFactory extends Factory
{
    protected $model = PackagingMethod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
