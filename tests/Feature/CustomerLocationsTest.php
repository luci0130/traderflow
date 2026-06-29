<?php

namespace Tests\Feature;

use App\Modules\Customers\Enums\CustomerLocationType;
use App\Modules\Customers\Models\CustomerLocation;
use Database\Factories\SupermarketFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_have_multiple_typed_locations(): void
    {
        $customer = SupermarketFactory::new()->create(['name' => 'Mega Image']);

        $supermarket = CustomerLocation::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Mega Image Dorobanti',
            'type' => CustomerLocationType::Supermarket,
        ]);
        $warehouse = CustomerLocation::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Mega Image Depozit Cluj',
            'type' => CustomerLocationType::Warehouse,
        ]);

        $this->assertCount(2, $customer->fresh()->locations);
        $this->assertTrue($customer->locations->contains($supermarket));
        $this->assertTrue($customer->locations->contains($warehouse));
        $this->assertSame(CustomerLocationType::Warehouse, $warehouse->fresh()->type);
    }

    public function test_location_stores_separate_legal_entity_billing_details(): void
    {
        $customer = SupermarketFactory::new()->create();

        $location = CustomerLocation::factory()->create([
            'customer_id' => $customer->id,
            'is_separate_legal_entity' => true,
            'legal_name' => 'Depozit Central SRL',
            'fiscal_code' => 'RO99887766',
            'bank_name' => 'BCR',
            'bank_account' => 'RO12BCRL0000000000000000',
        ]);

        $fresh = $location->fresh();

        $this->assertTrue($fresh->is_separate_legal_entity);
        $this->assertSame('Depozit Central SRL', $fresh->legal_name);
        $this->assertSame('RO99887766', $fresh->fiscal_code);
        $this->assertSame('BCR', $fresh->bank_name);
        $this->assertSame('RO12BCRL0000000000000000', $fresh->bank_account);
    }

    public function test_location_display_name_uses_name_city_and_address(): void
    {
        $location = CustomerLocation::factory()->make([
            'name' => 'Auchan Iris',
            'city' => 'Cluj-Napoca',
            'address' => 'Bulevardul Muncii 1-15',
        ]);

        $this->assertSame('Auchan Iris - Cluj-Napoca - Bulevardul Muncii 1-15', $location->displayName());
    }
}
