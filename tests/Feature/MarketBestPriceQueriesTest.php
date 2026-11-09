<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
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

    protected function setUp(): void
    {
        parent::setUp();

        CurrencyExchangeRate::factory()->create();
        CurrencyExchangeRate::factory()->inverse()->create();
    }

    public function test_supplier_candidates_are_ranked_after_currency_conversion(): void
    {
        $canonical = CanonicalProduct::factory()->create();
        $supplier = Supplier::create(['name' => 'Mixed currencies']);
        $eur = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'EUR product', 'status' => 'active', 'unit_price' => 2, 'currency' => 'EUR']);
        $ron = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'RON product', 'status' => 'active', 'unit_price' => 6, 'currency' => 'RON']);
        $canonical->supplierProducts()->attach([$eur->id, $ron->id]);

        $candidates = app(SupplierBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertSame([$ron->id, $eur->id], $candidates->map(fn ($candidate): int => $candidate->supplierProduct->id)->all());
        $this->assertSame(2.0, $candidates->last()->unitPrice);
        $this->assertSame('EUR', $candidates->last()->currency);
    }

    public function test_supplier_prices_in_the_same_currency_remain_ranked_without_exchange_rates(): void
    {
        CurrencyExchangeRate::query()->delete();
        $canonical = CanonicalProduct::factory()->create();
        $supplier = Supplier::create(['name' => 'EUR supplier']);
        $expensive = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Expensive', 'status' => 'active', 'unit_price' => 10, 'currency' => 'EUR',
        ]);
        $cheap = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => 'Cheap', 'status' => 'active', 'unit_price' => 1, 'currency' => 'EUR',
        ]);
        $canonical->supplierProducts()->attach([$expensive->id, $cheap->id]);

        $candidates = app(SupplierBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertSame([$cheap->id, $expensive->id], $candidates->map(fn ($candidate): int => $candidate->supplierProduct->id)->all());
    }

    public function test_supplier_costs_are_converted_into_the_product_currency_before_adding_them(): void
    {
        $canonical = CanonicalProduct::factory()->create();
        $supplier = Supplier::create(['name' => 'Mixed cost currencies']);
        SupplierCostDefault::create(['supplier_id' => $supplier->id, 'transport_cost' => 0.2, 'currency' => 'EUR']);
        $product = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'RON product', 'status' => 'active', 'unit_price' => 3, 'currency' => 'RON']);
        $canonical->supplierProducts()->attach($product);

        $candidate = app(SupplierBestPriceQuery::class)->bestFor($canonical);

        $this->assertSame(3.0, $candidate->unitPrice);
        $this->assertSame(4.0, $candidate->landedCost);
        $this->assertSame('RON', $candidate->currency);
        $this->assertSame('3.0000', $product->fresh()->unit_price);
    }

    public function test_supermarket_candidates_are_ranked_after_currency_conversion(): void
    {
        $canonical = CanonicalProduct::factory()->create();
        $product = SupermarketProduct::factory()->create(['vat_rate' => 0]);
        $canonical->supermarketProducts()->attach($product);
        $eur = SupermarketPrice::factory()->create(['supermarket_product_id' => $product->id, 'price' => 3, 'currency' => 'EUR']);
        $ron = SupermarketPrice::factory()->create(['supermarket_product_id' => $product->id, 'price' => 9, 'currency' => 'RON']);

        $candidates = app(SupermarketBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertSame([$eur->id, $ron->id], $candidates->map(fn ($candidate): int => $candidate->price->id)->all());
    }

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

        foreach ([['Profi', 7], ['Mega', 9], ['Penny', 6]] as [$name, $price]) {
            $supermarket = Customer::create(['name' => $name, 'tenant_id' => null]);

            SupermarketPrice::create([
                'supermarket_id' => $supermarket->id,
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

    public function test_only_the_latest_price_per_supermarket_is_listed(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $product = SupermarketProduct::factory()->create();
        $otherProduct = SupermarketProduct::factory()->create();
        $canonical->supermarketProducts()->attach([$product->id, $otherProduct->id]);

        $kaufland = Customer::create(['name' => 'Kaufland Gara', 'tenant_id' => null]);
        $carrefour = Customer::create(['name' => 'Carrefour Dambu', 'tenant_id' => null]);

        SupermarketPrice::create([
            'supermarket_id' => $kaufland->id,
            'supermarket_product_id' => $product->id,
            'price' => 12,
            'observed_at' => today()->subDays(20),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $kaufland->id,
            'supermarket_product_id' => $otherProduct->id,
            'price' => 5,
            'observed_at' => today()->subDays(2),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $carrefour->id,
            'supermarket_product_id' => $product->id,
            'price' => 8,
            'observed_at' => today()->subDays(1),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        $candidates = app(SupermarketBestPriceQuery::class)->candidatesFor($canonical);

        $this->assertCount(2, $candidates);
        $this->assertSame(['Carrefour Dambu', 'Kaufland Gara'], $candidates->map(fn ($candidate): string => $candidate->supermarketName)->all());
        $this->assertSame([8.0, 5.0], $candidates->map(fn ($candidate): float => $candidate->grossPrice)->all());
    }

    public function test_the_best_supermarket_price_ignores_stale_observations_from_the_same_store(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $product = SupermarketProduct::factory()->create();
        $canonical->supermarketProducts()->attach($product);

        $kaufland = Customer::create(['name' => 'Kaufland Gara', 'tenant_id' => null]);
        $lidl = Customer::create(['name' => 'LDL Gara', 'tenant_id' => null]);

        SupermarketPrice::create([
            'supermarket_id' => $kaufland->id,
            'supermarket_product_id' => $product->id,
            'price' => 15,
            'observed_at' => today()->subDays(30),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $kaufland->id,
            'supermarket_product_id' => $product->id,
            'price' => 6,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        SupermarketPrice::create([
            'supermarket_id' => $lidl->id,
            'supermarket_product_id' => $product->id,
            'price' => 9,
            'observed_at' => today()->subDays(3),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        $best = app(SupermarketBestPriceQuery::class)->bestFor($canonical);

        $this->assertSame('LDL Gara', $best->supermarketName);
        $this->assertSame(9.0, $best->grossPrice);
    }
}
