<?php

namespace Tests\Feature;

use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Filament\Resources\PackagingMethods\PackagingMethodResource;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagingMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_packaging_method_resource_is_registered(): void
    {
        $this->assertSame(PackagingMethod::class, PackagingMethodResource::getModel());
    }

    public function test_default_packaging_methods_are_seeded(): void
    {
        $this->assertSame(
            ['Vrac', 'Plasă', 'Ladă', 'Cutie', 'Bucată', 'Sac'],
            PackagingMethod::query()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_packaging_method_can_be_attached_to_supplier_and_supermarket_products(): void
    {
        $method = PackagingMethod::query()->where('name', 'Plasă')->firstOrFail();
        $producer = Producer::create(['name' => 'Ferma Verde', 'status' => 'active']);

        $supplierProduct = SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Portocale',
            'packaging_method_id' => $method->id,
            'default_packaging' => 'Plasă 2kg',
            'status' => 'active',
            'currency' => 'EUR',
        ]);
        $supermarketProduct = SupermarketProduct::factory()->create([
            'name' => 'Portocale plasa 2kg',
            'packaging_method_id' => $method->id,
            'package_size' => 2,
            'package_unit' => 'kg',
        ]);

        $this->assertTrue($supplierProduct->packagingMethod->is($method));
        $this->assertTrue($supermarketProduct->packagingMethod->is($method));
        $this->assertCount(1, $method->supplierProducts);
        $this->assertCount(1, $method->supermarketProducts);
    }
}
