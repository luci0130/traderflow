<?php

namespace Database\Factories;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierLead>
 */
class SupplierLeadFactory extends Factory
{
    protected $model = SupplierLead::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country' => fake()->country(),
            'website' => fake()->url(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'notes' => fake()->optional()->sentence(),
            'created_by' => null,
            'converted_supplier_id' => null,
            'converted_at' => null,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'converted_supplier_id' => fn (): int => Supplier::create([
                'tenant_id' => null,
                'name' => $attributes['name'] ?? fake()->company(),
                'status' => 'active',
            ])->getKey(),
            'converted_at' => now(),
        ]);
    }
}
