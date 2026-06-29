<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds a globally shared {@see Customer} that represents a supermarket
 * (no tenant_id, visible across every tenant).
 *
 * @extends Factory<Customer>
 */
class SupermarketFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Kaufland', 'Lidl', 'Carrefour', 'Mega Image', 'Profi', 'Auchan']).' '.fake()->city();

        return [
            'tenant_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'country' => 'RO',
            'status' => 'active',
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        // The BelongsToTenant creating hook assigns the active tenant to any
        // null tenant_id; force the record back to global after creation.
        return $this->afterCreating(function (Customer $customer): void {
            if ($customer->tenant_id !== null) {
                $customer->forceFill(['tenant_id' => null])->saveQuietly();
            }
        });
    }
}
