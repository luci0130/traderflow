<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Reports\Services\OfferRevenueCalculator;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfferRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Profit tenant']);
    }

    /** @return array{CustomerOffer, CustomerOfferItem} */
    private function offer(float $quantity = 100, float $salePrice = 10): array
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Customer']);
        $offer = CustomerOffer::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'status' => 'draft',
            'currency' => 'EUR', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $item = $offer->items()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => Product::create(['name' => 'Tomatoes'])->id,
            'quantity' => $quantity, 'sale_price' => $salePrice, 'purchase_price' => 999, 'tax_rate' => 19,
        ]);

        return [$offer, $item];
    }

    /** @param array<string, mixed> $attributes */
    private function source(CustomerOfferItem $item, array $attributes = []): CustomerOfferItemSupplier
    {
        return $item->suppliers()->create(array_merge([
            'supplier_id' => Supplier::create(['name' => 'Supplier'])->id,
            'priority' => $item->suppliers()->count() + 1,
            'unit_price' => 7.5, 'secured_quantity' => 100,
            'currency' => 'EUR', 'transport_cost' => 100, 'include_in_order' => true,
        ], $attributes));
    }

    public function test_example_has_1000_sales_750_products_100_transport_and_150_profit(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);

        $this->assertSame(1000.0, $result->salesTotal);
        $this->assertSame(750.0, $result->productCost);
        $this->assertSame(250.0, $result->margin());
        $this->assertSame(100.0, $result->transportCost);
        $this->assertSame(150.0, $result->profit());
    }

    public function test_selected_supplier_prices_and_actual_quantities_are_summed_without_using_the_item_average(): void
    {
        [$offer, $item] = $this->offer(quantity: 999);
        $this->source($item, ['unit_price' => 5, 'secured_quantity' => 10, 'transport_cost' => 7]);
        $this->source($item, ['unit_price' => 8, 'secured_quantity' => 30, 'transport_cost' => 13]);
        $this->source($item, ['unit_price' => 100, 'secured_quantity' => 500, 'transport_cost' => 999, 'include_in_order' => false]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);

        $this->assertSame(400.0, $result->salesTotal);
        $this->assertSame(290.0, $result->productCost);
        $this->assertSame(20.0, $result->transportCost);
        $this->assertSame(90.0, $result->profit());
    }

    public function test_transport_is_not_subtracted_again_from_an_item_average_that_already_contains_it(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $item->update(['purchase_price' => 8.5]);
        $this->assertSame(150.0, app(OfferRevenueCalculator::class)->calculate($offer)->profit());
    }

    public function test_legacy_landed_price_including_transport_is_split_without_double_counting(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => null, 'landed_cost' => 8.5]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertTrue($result->hasBundledCosts);
        $this->assertSame(750.0, $result->productCost);
        $this->assertSame(100.0, $result->transportCost);
        $this->assertSame(150.0, $result->profit());
    }

    public function test_prices_and_transport_in_another_currency_are_converted_before_subtraction(): void
    {
        CurrencyExchangeRate::factory()->inverse()->create();
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => 37.5, 'transport_cost' => 500, 'currency' => 'RON']);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertSame(750.0, $result->productCost);
        $this->assertSame(100.0, $result->transportCost);
        $this->assertSame(150.0, $result->profit());
    }

    public function test_missing_exchange_rate_does_not_fabricate_a_zero_cost_or_profit(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['currency' => 'USD']);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertNull($result->productCost);
        $this->assertNull($result->transportCost);
        $this->assertNull($result->profit());
        $this->assertFalse($result->isComplete);
    }

    public function test_unconfirmed_quantities_are_allocated_by_priority_and_supplier_availability(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => 5, 'secured_quantity' => null, 'quantity_available' => 40, 'transport_cost' => 20]);
        $this->source($item, ['unit_price' => 8, 'secured_quantity' => null, 'quantity_available' => 100, 'transport_cost' => 30]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertTrue($result->usesEstimatedQuantities);
        $this->assertSame(1000.0, $result->salesTotal);
        $this->assertSame(680.0, $result->productCost);
        $this->assertSame(270.0, $result->profit());
    }

    public function test_confirmed_quantities_are_reserved_before_allocating_the_remaining_estimate(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => 5, 'secured_quantity' => null, 'transport_cost' => 0]);
        $this->source($item, ['unit_price' => 8, 'secured_quantity' => 30, 'transport_cost' => 0]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertSame(590.0, $result->productCost);
        $this->assertSame(410.0, $result->profit());
    }

    public function test_unused_supplier_candidates_do_not_add_transport_or_duplicate_quantities(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $this->source($item, ['unit_price' => 999, 'secured_quantity' => null, 'transport_cost' => 1000]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertSame(100.0, $result->transportCost);
        $this->assertSame(150.0, $result->profit());
    }

    public function test_insufficient_available_quantities_are_marked_incomplete(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['secured_quantity' => null, 'quantity_available' => 10]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertFalse($result->isComplete);
        $this->assertNull($result->profit());
    }

    public function test_missing_product_price_keeps_profit_unknown(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => null]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertNull($result->productCost);
        $this->assertNull($result->profit());
    }

    public function test_zero_quantity_never_charges_unused_transport_or_divides_by_zero(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['secured_quantity' => 0]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertSame(0.0, $result->transportCost);
        $this->assertNull($result->profit());
    }

    public function test_losses_are_not_clamped_to_zero(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item, ['unit_price' => 12]);
        $this->assertSame(-300.0, app(OfferRevenueCalculator::class)->calculate($offer)->profit());
    }

    public function test_accepting_an_offer_preserves_exact_supplier_costs_and_transport_for_the_report(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $order = app(CustomerOfferAcceptor::class)->accept($offer);
        $result = app(OfferRevenueCalculator::class)->calculate($offer->fresh());
        $this->assertSame(150.0, $result->profit());
        $this->assertSame('draft', $order->status);
        $this->assertFalse($result->usesEstimatedQuantities);
    }

    public function test_sales_order_actual_sale_prices_and_quantities_override_the_original_offer(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $order = app(CustomerOfferAcceptor::class)->accept($offer);
        $order->items()->first()->update(['sale_price' => 12, 'quantity' => 80, 'line_total' => 99999]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer->fresh());
        $this->assertSame(960.0, $result->salesTotal);
        $this->assertSame(110.0, $result->profit());
    }

    public function test_empty_offer_has_no_fabricated_profit(): void
    {
        [$offer, $item] = $this->offer();
        $item->delete();
        $this->assertNull(app(OfferRevenueCalculator::class)->calculate($offer->fresh())->profit());
    }

    public function test_calculating_eager_loaded_rows_does_not_issue_queries_per_offer(): void
    {
        [$offer, $item] = $this->offer();
        $this->source($item);
        $offers = CustomerOffer::query()->with(['items.suppliers', 'salesOrder.items'])->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($offers as $record) {
            app(OfferRevenueCalculator::class)->calculate($record);
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_legacy_single_supplier_cost_is_not_charged_transport_twice(): void
    {
        [$offer, $item] = $this->offer();
        $item->update([
            'supplier_id' => Supplier::create(['name' => 'Legacy supplier'])->id,
            'purchase_price' => 8.5,
        ]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertTrue($result->hasBundledCosts);
        $this->assertSame(850.0, $result->productCost);
        $this->assertSame(0.0, $result->transportCost);
        $this->assertSame(150.0, $result->profit());
    }

    public function test_item_average_without_any_selected_supplier_is_not_treated_as_a_purchase(): void
    {
        [$offer] = $this->offer();
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertNull($result->productCost);
        $this->assertNull($result->profit());
    }

    public function test_rounding_a_weighted_unit_cost_does_not_change_the_exact_transport_total(): void
    {
        [$offer, $item] = $this->offer(quantity: 3);
        $this->source($item, ['unit_price' => 5, 'secured_quantity' => 3, 'transport_cost' => 1]);
        $item->update(['purchase_price' => 5.3333]);
        $result = app(OfferRevenueCalculator::class)->calculate($offer);
        $this->assertSame(15.0, $result->productCost);
        $this->assertSame(1.0, $result->transportCost);
        $this->assertSame(14.0, $result->profit());
    }
}
