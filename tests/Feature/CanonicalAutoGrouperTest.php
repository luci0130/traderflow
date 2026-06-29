<?php

namespace Tests\Feature;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\CanonicalAutoGrouper;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalAutoGrouperTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_supplier_and_supermarket_products_by_name_category_packaging(): void
    {
        $rosii = ProductCategory::create(['tenant_id' => null, 'name' => 'Roșii']);
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Verde']);

        // Same product, "250 g" packaging — supplier + supermarket should merge.
        $supA = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Roșii cherry', 'category' => 'Roșii',
            'default_packaging' => '250 g', 'min_quantity_unit' => 'kg', 'status' => 'active', 'currency' => 'RON',
        ]);
        $smA = SupermarketProduct::create([
            'name' => 'Roșii cherry', 'category' => 'Roșii', 'package_size' => 250, 'package_unit' => 'g',
        ]);

        // Same product but "kg" packaging — must land in a separate canonical.
        $supB = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Roșii cherry', 'category' => 'Roșii',
            'default_packaging' => 'kg', 'min_quantity_unit' => 'kg', 'status' => 'active', 'currency' => 'RON',
        ]);
        $smB = SupermarketProduct::create([
            'name' => 'Roșii cherry', 'category' => 'Roșii', 'package_size' => null, 'package_unit' => 'kg',
        ]);

        $stats = app(CanonicalAutoGrouper::class)->group();

        // Two packaging variants => two canonical products.
        $this->assertSame(2, CanonicalProduct::query()->count());
        $this->assertSame(2, $stats['canonicals_created']);
        $this->assertSame(2, $stats['supplier_mapped']);
        $this->assertSame(2, $stats['supermarket_mapped']);

        $canon250 = CanonicalProduct::query()->where('packaging_variant', '250 g')->firstOrFail();
        $canonKg = CanonicalProduct::query()->where('packaging_variant', 'kg')->firstOrFail();

        $this->assertSame($rosii->id, $canon250->product_category_id);
        $this->assertEqualsCanonicalizing([$supA->id], $canon250->supplierProducts->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$smA->id], $canon250->supermarketProducts->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$supB->id], $canonKg->supplierProducts->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$smB->id], $canonKg->supermarketProducts->pluck('id')->all());
    }

    public function test_groups_by_country_of_origin_regardless_of_how_it_was_written(): void
    {
        ProductCategory::create(['tenant_id' => null, 'name' => 'Roșii']);
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Verde']);

        // Supplier stores the ISO code, supermarket the Romanian name — both Morocco.
        $supMa = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Roșii cherry', 'category' => 'Roșii',
            'country_of_origin' => 'MA', 'default_packaging' => 'kg', 'min_quantity_unit' => 'kg',
            'status' => 'active', 'currency' => 'RON',
        ]);
        $smMa = SupermarketProduct::create([
            'name' => 'Roșii cherry', 'category' => 'Roșii', 'origin' => 'Maroc', 'package_unit' => 'kg',
        ]);

        // Different origin => must land in a separate canonical.
        $smTr = SupermarketProduct::create([
            'name' => 'Roșii cherry', 'category' => 'Roșii', 'origin' => 'Turcia', 'package_unit' => 'kg',
        ]);

        $stats = app(CanonicalAutoGrouper::class)->group();

        $this->assertSame(2, CanonicalProduct::query()->count());
        $this->assertSame(2, $stats['canonicals_created']);

        $canonMa = CanonicalProduct::query()->where('country_of_origin', 'MA')->firstOrFail();
        $canonTr = CanonicalProduct::query()->where('country_of_origin', 'TR')->firstOrFail();

        $this->assertEqualsCanonicalizing([$supMa->id], $canonMa->supplierProducts->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$smMa->id], $canonMa->supermarketProducts->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$smTr->id], $canonTr->supermarketProducts->pluck('id')->all());
    }

    public function test_models_normalize_country_input_to_iso_codes_on_save(): void
    {
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Verde']);

        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Afine', 'category' => 'Fructe',
            'country_of_origin' => 'Maroc', 'status' => 'active', 'currency' => 'RON',
        ]);
        $supermarketProduct = SupermarketProduct::create([
            'name' => 'Afine', 'category' => 'Fructe', 'origin' => 'Morocco', 'package_unit' => 'g',
        ]);

        $this->assertSame('MA', $supplierProduct->refresh()->country_of_origin);
        $this->assertSame('MA', $supermarketProduct->refresh()->origin);
    }

    public function test_is_idempotent_and_skips_already_mapped_products(): void
    {
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Verde']);
        SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Banane', 'category' => 'Banane',
            'default_packaging' => 'kg', 'min_quantity_unit' => 'kg', 'status' => 'active', 'currency' => 'RON',
        ]);
        SupermarketProduct::create(['name' => 'Banane', 'category' => 'Banane', 'package_unit' => 'kg']);

        $first = app(CanonicalAutoGrouper::class)->group();
        $this->assertSame(1, $first['canonicals_created']);

        $second = app(CanonicalAutoGrouper::class)->group();

        $this->assertSame(0, $second['canonicals_created']);
        $this->assertSame(0, $second['supplier_mapped']);
        $this->assertSame(0, $second['supermarket_mapped']);
        $this->assertSame(1, CanonicalProduct::query()->count());
    }
}
