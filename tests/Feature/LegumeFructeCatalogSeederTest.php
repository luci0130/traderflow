<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Seeders\LegumeFructeCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegumeFructeCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupermarket(string $name): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'tenant_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'is_active' => true,
        ])->saveQuietly();

        return $customer;
    }

    public function test_seeds_supermarket_and_supplier_catalog(): void
    {
        $this->makeSupermarket('Carrefour');
        $this->makeSupermarket('Bringo');
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Test', 'status' => 'active']);

        $this->seed(LegumeFructeCatalogSeeder::class);

        $this->assertGreaterThan(30, SupermarketProduct::count());
        $this->assertGreaterThan(0, SupermarketPrice::count());
        $this->assertGreaterThan(30, SupplierProduct::count());

        // Bio flag is populated on both sides of the market.
        $this->assertTrue(SupermarketProduct::query()->where('is_bio', true)->exists());
        $this->assertTrue(SupplierProduct::query()->where('is_bio', true)->exists());

        // Supplier products carry tiered prices and a cost override.
        $product = SupplierProduct::query()
            ->with(['prices', 'costOverride'])
            ->where('producer_id', $supplier->getKey())
            ->first();

        $this->assertNotNull($product);
        $this->assertCount(3, $product->prices);
        $this->assertNotNull($product->costOverride);
        // The first tier mirrors the default unit price.
        $this->assertSame($product->unit_price, $product->prices->firstWhere('sort_order', 0)->unit_price);

        // Shelf prices reference the seeded chains and carry the produce VAT rate.
        $this->assertSame('11.00', SupermarketProduct::query()->value('vat_rate'));
    }

    public function test_is_idempotent_and_wipes_previous_products(): void
    {
        $this->makeSupermarket('Carrefour');
        $this->makeSupermarket('Bringo');
        Supplier::create(['tenant_id' => null, 'name' => 'Ferma Test', 'status' => 'active']);

        $stale = SupermarketProduct::create(['name' => 'STALE PRODUCT']);

        $this->seed(LegumeFructeCatalogSeeder::class);
        $afterFirst = SupermarketProduct::count();
        $supplierAfterFirst = SupplierProduct::count();

        $this->assertDatabaseMissing('supermarket_products', ['id' => $stale->getKey()]);

        $this->seed(LegumeFructeCatalogSeeder::class);

        $this->assertSame($afterFirst, SupermarketProduct::count());
        $this->assertSame($supplierAfterFirst, SupplierProduct::count());
    }

    public function test_skips_when_no_suppliers_or_supermarkets(): void
    {
        $this->seed(LegumeFructeCatalogSeeder::class);

        $this->assertSame(0, SupermarketProduct::query()->count());
        $this->assertSame(0, SupplierProduct::query()->count());
    }
}
