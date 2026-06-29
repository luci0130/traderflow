<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketBestPriceQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_best_price_picks_the_lowest_landed_cost_not_the_lowest_unit_price(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $cheapUnitPriceSupplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $cheapUnitPriceSupplier->id,
            'transport_cost' => 4,
        ]);
        $cheapUnitPriceProduct = SupplierProduct::create([
            'producer_id' => $cheapUnitPriceSupplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 5,
        ]);

        $cheapLandedSupplier = Supplier::create(['name' => 'Agricola Dambovita SA']);
        $cheapLandedProduct = SupplierProduct::create([
            'producer_id' => $cheapLandedSupplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 6,
        ]);

        $canonical->supplierProducts()->attach([$cheapUnitPriceProduct->id, $cheapLandedProduct->id]);

        $best = app(SupplierBestPriceQuery::class)->bestFor($canonical);

        $this->assertSame('Agricola Dambovita SA', $best->supplierName);
        $this->assertSame(6.0, $best->landedCost);

        $candidates = app(SupplierBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertCount(2, $candidates);
        $this->assertSame(9.0, $candidates->last()->landedCost);
    }

    public function test_supplier_candidate_exposes_the_country_of_origin(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);

        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 5,
            'country_of_origin' => 'Spania',
        ]);

        $canonical->supplierProducts()->attach($product->id);

        $best = app(SupplierBestPriceQuery::class)->bestFor($canonical);

        // Stored normalized to the ISO code; the page formats it to a label for display.
        $this->assertSame('ES', $best->countryOfOrigin);
    }

    public function test_supplier_best_price_ignores_archived_expired_and_unpriced_products(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);

        $archived = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'archived',
            'unit_price' => 1,
        ]);
        $expired = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 2,
            'valid_until' => today()->subDay(),
        ]);
        $unpriced = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
        ]);
        $valid = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 5,
            'valid_until' => today()->addWeek(),
        ]);

        $canonical->supplierProducts()->attach([$archived->id, $expired->id, $unpriced->id, $valid->id]);

        $candidates = app(SupplierBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates->first()->supplierProduct->is($valid));
    }

    public function test_supermarket_best_price_picks_the_highest_recent_price(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $product = SupermarketProduct::factory()->create(['name' => 'Portocale plasa 2kg', 'vat_rate' => 0]);
        $canonical->supermarketProducts()->attach($product);

        $auchan = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);
        $carrefour = Customer::create(['name' => 'Carrefour', 'tenant_id' => null]);

        SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $product->id,
            'price' => 9,
            'observed_at' => today()->subDays(3),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $carrefour->id,
            'supermarket_product_id' => $product->id,
            'price' => 8,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        $best = app(SupermarketBestPriceQuery::class)->bestFor($canonical);

        $this->assertSame('Auchan', $best->supermarketName);
        $this->assertSame(9.0, $best->grossPrice);
        $this->assertSame(9.0, $best->priceExclVat);
    }

    public function test_supermarket_prices_outside_the_recency_window_are_ignored(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $product = SupermarketProduct::factory()->create();
        $canonical->supermarketProducts()->attach($product);

        $auchan = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $product->id,
            'price' => 20,
            'observed_at' => today()->subDays(90),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $product->id,
            'price' => 8,
            'observed_at' => today()->subDays(5),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        $candidates = app(SupermarketBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertCount(1, $candidates);
        $this->assertSame(8.0, $candidates->first()->grossPrice);
    }

    public function test_supermarket_candidates_are_sorted_by_price_descending(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $product = SupermarketProduct::factory()->create();
        $canonical->supermarketProducts()->attach($product);

        $profi = Customer::create(['name' => 'Profi', 'tenant_id' => null]);

        foreach ([7, 9, 6] as $price) {
            SupermarketPrice::create([
                'supermarket_id' => $profi->id,
                'supermarket_product_id' => $product->id,
                'price' => $price,
                'observed_at' => today(),
                'source' => SupermarketPrice::SOURCE_MANUAL,
            ]);
        }

        $prices = app(SupermarketBestPriceQuery::class)
            ->candidatesFor($canonical)
            ->map(fn ($candidate): float => $candidate->grossPrice)
            ->all();

        $this->assertSame([9.0, 7.0, 6.0], $prices);
    }
}
