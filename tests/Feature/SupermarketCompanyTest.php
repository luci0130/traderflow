<?php

namespace Tests\Feature;

use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupermarketCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_can_remain_unassigned(): void
    {
        $price = SupermarketPrice::factory()->create();

        $this->assertNull($price->supplier_lead_id);
        $this->assertNull($price->supplier_id);
        $this->assertNull($price->company);
    }

    public function test_the_same_product_can_have_separate_company_and_monthly_price_histories(): void
    {
        $product = SupermarketProduct::factory()->create();
        $firstLead = SupplierLead::factory()->create();
        $secondLead = SupplierLead::factory()->create();
        $march = SupermarketPrice::factory()->create([
            'supermarket_product_id' => $product->id, 'supplier_lead_id' => $firstLead->id,
            'observed_at' => '2026-03-15', 'price' => 10,
        ]);
        $april = SupermarketPrice::factory()->create([
            'supermarket_product_id' => $product->id, 'supplier_lead_id' => $firstLead->id,
            'observed_at' => '2026-04-15', 'price' => 11,
        ]);
        $other = SupermarketPrice::factory()->create([
            'supermarket_product_id' => $product->id, 'supplier_lead_id' => $secondLead->id,
            'observed_at' => '2026-03-15', 'price' => 12,
        ]);

        $this->assertSame([$march->id, $april->id], $firstLead->supermarketPrices()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$other->id], $secondLead->supermarketPrices()->pluck('id')->all());
        $this->assertSame(['10.0000', '11.0000'], $firstLead->supermarketPrices()->orderBy('observed_at')->get()->pluck('price')->all());
        $this->assertCount(2, $product->supplierLeads);
        $this->assertDatabaseCount('supermarket_product_companies', 2);
    }

    public function test_multiple_products_can_be_associated_without_prices(): void
    {
        $lead = SupplierLead::factory()->create();
        $supplier = Supplier::create(['name' => 'Existing supplier']);
        $products = SupermarketProduct::factory()->count(2)->create();

        $lead->supermarketProducts()->syncWithoutDetaching($products->modelKeys());
        $supplier->supermarketProducts()->syncWithoutDetaching($products->modelKeys());

        $this->assertCount(2, $lead->supermarketProducts);
        $this->assertCount(2, $supplier->supermarketProducts);
        $this->assertDatabaseCount('supermarket_prices', 0);
    }

    public function test_assigning_an_existing_supplier_also_associates_the_product(): void
    {
        $supplier = Supplier::create(['name' => 'Existing supplier']);
        $price = SupermarketPrice::factory()->create(['company' => 'supplier:'.$supplier->id]);

        $this->assertSame($supplier->id, $price->supplier_id);
        $this->assertNull($price->supplier_lead_id);
        $this->assertTrue($supplier->supermarketProducts->contains($price->product));
        $this->assertTrue($price->product->suppliers->contains($supplier));
    }

    public function test_conversion_preserves_the_lead_links_and_exposes_them_to_the_supplier(): void
    {
        $lead = SupplierLead::factory()->create();
        $extraProduct = SupermarketProduct::factory()->create();
        $lead->supermarketProducts()->attach($extraProduct);
        $price = SupermarketPrice::factory()->create(['supplier_lead_id' => $lead->id]);
        $supplier = Supplier::create(['name' => $lead->name]);

        $lead->update(['converted_supplier_id' => $supplier->id, 'converted_at' => now()]);

        $this->assertSame($supplier->id, $price->fresh()->supplier_id);
        $this->assertSame($lead->id, $price->fresh()->supplier_lead_id);
        $this->assertTrue($supplier->supermarketPrices->contains($price));
        $this->assertTrue($lead->supermarketPrices->contains($price));
        $this->assertCount(2, $supplier->supermarketProducts);
        $this->assertDatabaseCount('supermarket_product_companies', 2);
    }

    public function test_new_prices_and_products_after_conversion_are_available_on_both_pages(): void
    {
        $lead = SupplierLead::factory()->converted()->create();
        $fromLead = SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id]);
        $fromSupplier = SupermarketPrice::factory()->create(['company' => 'supplier:'.$lead->converted_supplier_id]);
        $extra = SupermarketProduct::factory()->create();
        $lead->convertedSupplier->supermarketProducts()->attach($extra);

        foreach ([$fromLead, $fromSupplier] as $price) {
            $this->assertSame($lead->id, $price->supplier_lead_id);
            $this->assertSame($lead->converted_supplier_id, $price->supplier_id);
        }

        $this->assertCount(2, $lead->supermarketPrices);
        $this->assertCount(2, $lead->convertedSupplier->supermarketPrices);
        $this->assertCount(3, $lead->supermarketProducts);
        $this->assertCount(3, $lead->convertedSupplier->supermarketProducts);
    }

    public function test_a_price_cannot_link_two_unrelated_companies(): void
    {
        $lead = SupplierLead::factory()->create();
        $supplier = Supplier::create(['name' => 'Different company']);

        $this->expectException(ValidationException::class);

        SupermarketPrice::factory()->create(['supplier_lead_id' => $lead->id, 'supplier_id' => $supplier->id]);
    }

    public function test_reassigning_a_price_uses_the_new_company_even_if_the_old_relation_was_loaded(): void
    {
        $lead = SupplierLead::factory()->create();
        $other = SupplierLead::factory()->create();
        $price = SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id]);
        $price->load('supplierLead');
        $newProduct = SupermarketProduct::factory()->create();

        $price->update(['company' => 'lead:'.$other->id, 'supermarket_product_id' => $newProduct->id]);

        $this->assertTrue($other->supermarketProducts->contains($newProduct));
        $this->assertFalse($lead->fresh()->supermarketProducts->contains($newProduct));
        $this->assertSame(0, $lead->supermarketPrices()->count());
    }

    public function test_removing_company_details_does_not_delete_the_observation(): void
    {
        $lead = SupplierLead::factory()->create();
        $price = SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id]);

        $price->update(['company' => null]);

        $this->assertNull($price->supplier_lead_id);
        $this->assertNull($price->supplier_id);
        $this->assertModelExists($price);
    }

    public function test_deleting_a_converted_lead_preserves_supplier_history(): void
    {
        $lead = SupplierLead::factory()->converted()->create();
        $supplier = $lead->convertedSupplier;
        $price = SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id]);

        $lead->delete();

        $this->assertNull($price->fresh()->supplier_lead_id);
        $this->assertSame($supplier->id, $price->fresh()->supplier_id);
        $this->assertTrue($supplier->supermarketPrices->contains($price));
        $this->assertTrue($supplier->supermarketProducts->contains($price->product));
    }

    public function test_legacy_links_are_backfilled_without_assigning_unattributed_prices(): void
    {
        $product = SupermarketProduct::factory()->create();
        $lead = SupplierLead::factory()->converted()->create();
        DB::table('supplier_leads')->where('id', $lead->id)->update(['supermarket_product_id' => $product->id]);
        $unassigned = SupermarketPrice::factory()->create(['supermarket_product_id' => $product->id]);
        $migration = require database_path('migrations/2026_11_09_194836_backfill_supermarket_product_companies.php');

        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('supermarket_product_companies', [
            'supermarket_product_id' => $product->id, 'supplier_lead_id' => $lead->id,
            'supplier_id' => $lead->converted_supplier_id,
        ]);
        $this->assertDatabaseCount('supermarket_product_companies', 1);
        $this->assertNull($unassigned->fresh()->company);
    }
}
