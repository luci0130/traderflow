<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerContact;
use App\Modules\Documents\Models\Document;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Factories\SupermarketFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupermarketPriceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_supermarket_entities_live_in_customers_table(): void
    {
        $this->assertFalse(Schema::hasTable('supermarkets'));
        $this->assertFalse(Schema::hasColumn('customers', 'type'));
        $this->assertTrue(Schema::hasColumns('customers', [
            'slug',
            'logo',
            'legal_name',
            'vat_number',
            'email',
            'phone',
            'country',
            'city',
            'address',
            'contact_person',
            'payment_terms',
            'status',
            'notes',
            'is_active',
        ]));

        foreach (['supermarket_products', 'supermarket_price_photos', 'supermarket_prices'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertFalse(
                Schema::hasColumn($table, 'tenant_id'),
                "Table [{$table}] should remain global and must not have a tenant_id column.",
            );
        }

        $this->assertTrue(Schema::hasColumn('supermarket_products', 'quality'));
    }

    public function test_a_price_links_supermarket_product_and_photo(): void
    {
        $supermarket = SupermarketFactory::new()->create();
        $product = SupermarketProduct::factory()->create();
        $photo = SupermarketPricePhoto::factory()->create(['supermarket_id' => $supermarket->id]);

        $price = SupermarketPrice::factory()->create([
            'supermarket_id' => $supermarket->id,
            'supermarket_product_id' => $product->id,
            'supermarket_price_photo_id' => $photo->id,
            'price' => 12.5,
        ]);

        $this->assertTrue($price->supermarket->is($supermarket));
        $this->assertTrue($price->product->is($product));
        $this->assertTrue($price->photo->is($photo));
        $this->assertEquals('12.5000', $price->price);
        $this->assertSame(1, $supermarket->prices()->count());
        $this->assertSame(1, $product->prices()->count());
        $this->assertSame(1, $photo->prices()->count());
    }

    public function test_a_supermarket_has_customer_fields_and_commercial_relationships(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Trading']);

        $supermarket = SupermarketFactory::new()->create([
            'legal_name' => 'Kaufland Romania SCS',
            'vat_number' => 'RO123',
            'email' => 'buyer@example.test',
            'phone' => '+40700000000',
            'city' => 'Bucuresti',
            'address' => 'Strada Exemplu 1',
            'contact_person' => 'Buyer One',
            'payment_terms' => '30 days',
            'notes' => 'Key account',
        ]);

        $customerOffer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $supermarket->id,
            'status' => 'draft',
            'currency' => 'EUR',
        ]);
        $salesOrder = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $supermarket->id,
            'status' => 'draft',
            'currency' => 'EUR',
        ]);
        $document = Document::create([
            'tenant_id' => $tenant->id,
            'documentable_type' => Customer::class,
            'documentable_id' => $supermarket->id,
            'type' => 'contract',
            'file_path' => 'documents/contract.pdf',
        ]);
        $contact = CustomerContact::create([
            'customer_id' => $supermarket->id,
            'name' => 'Buyer One',
            'email' => 'buyer@example.test',
            'is_primary' => true,
        ]);

        $this->assertNull($supermarket->tenant_id);
        $this->assertSame('Kaufland Romania SCS', $supermarket->legal_name);
        $this->assertTrue($supermarket->contacts->contains($contact));
        $this->assertTrue($supermarket->customerOffers->contains($customerOffer));
        $this->assertTrue($supermarket->salesOrders->contains($salesOrder));
        $this->assertTrue($supermarket->documents->contains($document));
        $this->assertTrue($customerOffer->customer->is($supermarket));
        $this->assertTrue($salesOrder->customer->is($supermarket));
    }

    public function test_casts_apply_to_price_attributes(): void
    {
        $price = SupermarketPrice::factory()->create([
            'is_promo' => true,
            'promo_price' => 9.99,
            'observed_at' => '2026-01-15',
        ]);

        $this->assertIsBool($price->is_promo);
        $this->assertTrue($price->is_promo);
        $this->assertInstanceOf(\DateTimeInterface::class, $price->observed_at);
        $this->assertSame('2026-01-15', $price->observed_at->toDateString());
    }

    public function test_price_without_vat_uses_the_product_vat_rate(): void
    {
        $product = SupermarketProduct::factory()->create([
            'vat_rate' => 11,
        ]);

        $price = SupermarketPrice::factory()->create([
            'supermarket_product_id' => $product->id,
            'price' => 111,
            'is_promo' => true,
            'promo_price' => 55.5,
        ]);

        $this->assertSame(11.0, $price->vatRate());
        $this->assertSame(100.0, $price->price_excl_vat);
        $this->assertSame(50.0, $price->promo_price_excl_vat);
    }

    public function test_price_without_vat_falls_back_to_the_default_rate(): void
    {
        $price = SupermarketPrice::factory()->make([
            'price' => 111,
            'promo_price' => null,
        ]);

        $this->assertSame(SupermarketProduct::DEFAULT_VAT_RATE, $price->vatRate());
        $this->assertSame(100.0, $price->price_excl_vat);
        $this->assertNull($price->promo_price_excl_vat);
    }
}
