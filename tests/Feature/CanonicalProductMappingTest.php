<?php

namespace Tests\Feature;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\CanonicalMatchSuggester;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CanonicalProductMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_tables_are_global_reference_data(): void
    {
        foreach (['canonical_products', 'canonical_supplier_product', 'canonical_supermarket_product'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertFalse(
                Schema::hasColumn($table, 'tenant_id'),
                "Table [{$table}] should remain global and must not have a tenant_id column.",
            );
        }
    }

    public function test_canonical_product_uses_the_global_product_category_taxonomy(): void
    {
        $citrice = ProductCategory::create(['tenant_id' => null, 'name' => 'Citrice']);
        $canonical = CanonicalProduct::factory()->create([
            'name' => 'Portocale',
            'product_category_id' => $citrice->id,
        ]);

        $this->assertTrue($canonical->category->is($citrice));
        $this->assertTrue($citrice->is($canonical->fresh()->category));
    }

    public function test_supplier_and_supermarket_products_link_transitively_through_a_canonical_product(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale', 'package_size' => 2, 'package_unit' => 'kg']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale Navel',
            'status' => 'active',
        ]);
        $supermarketProduct = SupermarketProduct::factory()->create(['name' => 'Portocale plasa 2kg']);

        $canonical->supplierProducts()->attach($supplierProduct);
        $canonical->supermarketProducts()->attach($supermarketProduct);

        $this->assertTrue($supplierProduct->canonicalProducts()->first()->is($canonical));
        $this->assertTrue($supermarketProduct->canonicalProducts()->first()->is($canonical));

        // The supplier <-> supermarket cross-mapping is derived through the shared canonical product.
        $linkedSupermarketProducts = $supplierProduct->canonicalProducts()->first()->supermarketProducts;

        $this->assertTrue($linkedSupermarketProducts->first()->is($supermarketProduct));
    }

    public function test_a_supplier_product_can_only_be_mapped_to_one_canonical_product(): void
    {
        $canonicalA = CanonicalProduct::factory()->create();
        $canonicalB = CanonicalProduct::factory()->create();

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
        ]);

        $canonicalA->supplierProducts()->attach($supplierProduct);

        $this->expectException(QueryException::class);

        $canonicalB->supplierProducts()->attach($supplierProduct);
    }

    public function test_a_supermarket_product_can_only_be_mapped_to_one_canonical_product(): void
    {
        $canonicalA = CanonicalProduct::factory()->create();
        $canonicalB = CanonicalProduct::factory()->create();

        $supermarketProduct = SupermarketProduct::factory()->create();

        $canonicalA->supermarketProducts()->attach($supermarketProduct);

        $this->expectException(QueryException::class);

        $canonicalB->supermarketProducts()->attach($supermarketProduct);
    }

    public function test_match_suggester_ranks_exact_name_matches_first_for_supplier_products(): void
    {
        $exact = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $partial = CanonicalProduct::factory()->create(['name' => 'Suc de portocale']);
        CanonicalProduct::factory()->create(['name' => 'Mandarine']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
        ]);

        $suggestions = app(CanonicalMatchSuggester::class)->suggestForSupplierProduct($supplierProduct);

        $this->assertCount(2, $suggestions);
        $this->assertTrue($suggestions->first()->is($exact));
        $this->assertTrue($suggestions->last()->is($partial));
    }

    public function test_match_suggester_prioritizes_barcode_matches_for_supermarket_products(): void
    {
        $byName = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $byBarcode = CanonicalProduct::factory()->create(['name' => 'Citrice vrac']);

        $mappedProduct = SupermarketProduct::factory()->create([
            'name' => 'Oranges bag',
            'barcode' => '5941234567890',
        ]);
        $byBarcode->supermarketProducts()->attach($mappedProduct);

        $newProduct = SupermarketProduct::factory()->create([
            'name' => 'Portocale plasa',
            'barcode' => '5941234567890',
        ]);

        $suggestions = app(CanonicalMatchSuggester::class)->suggestForSupermarketProduct($newProduct);

        $this->assertTrue($suggestions->first()->is($byBarcode));
        $this->assertTrue($suggestions->contains(fn (CanonicalProduct $candidate): bool => $candidate->is($byName)));
    }

    public function test_match_suggester_returns_nothing_without_usable_signals(): void
    {
        CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'ab',
            'status' => 'active',
        ]);

        $suggestions = app(CanonicalMatchSuggester::class)->suggestForSupplierProduct($supplierProduct);

        $this->assertCount(0, $suggestions);
    }
}
