<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferCalculator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOfferCalculationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_computes_line_item_totals(): void
    {
        $calculator = app(CustomerOfferCalculator::class);

        $this->assertSame(
            ['margin_value' => 1.0, 'margin_percent' => 25.0, 'line_total' => 25.0],
            $calculator->lineItemTotals(5.0, 4.0, 5.0),
        );
    }

    public function test_calculator_treats_null_quantity_and_zero_purchase_price_safely(): void
    {
        $calculator = app(CustomerOfferCalculator::class);

        $this->assertSame(
            ['margin_value' => 3.0, 'margin_percent' => 0.0, 'line_total' => 0.0],
            $calculator->lineItemTotals(null, 0.0, 3.0),
        );
    }

    public function test_creating_an_item_fills_its_totals_and_recalculates_the_offer(): void
    {
        [$tenant, $offer, $product] = $this->scaffold();

        $item = CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'purchase_price' => 4,
            'sale_price' => 5,
            'tax_rate' => 10,
        ]);

        $item->refresh();
        $this->assertSame('1.0000', $item->margin_value);
        $this->assertSame('25.0000', $item->margin_percent);
        $this->assertSame('25.0000', $item->line_total);

        $offer->refresh();
        $this->assertSame('25.0000', $offer->subtotal);
        $this->assertSame('2.5000', $offer->tax_total);
        $this->assertSame('27.5000', $offer->total);
    }

    public function test_updating_an_item_recomputes_the_offer(): void
    {
        [$tenant, $offer, $product] = $this->scaffold();

        $item = CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'purchase_price' => 5,
            'sale_price' => 6,
            'tax_rate' => 0,
        ]);

        $item->update([
            'quantity' => 4,
            'sale_price' => 7.5,
            'tax_rate' => 20,
        ]);

        $item->refresh();
        $this->assertSame('2.5000', $item->margin_value);
        $this->assertSame('50.0000', $item->margin_percent);
        $this->assertSame('30.0000', $item->line_total);

        $offer->refresh();
        $this->assertSame('30.0000', $offer->subtotal);
        $this->assertSame('6.0000', $offer->tax_total);
        $this->assertSame('36.0000', $offer->total);
    }

    public function test_deleting_an_item_recomputes_the_offer(): void
    {
        [$tenant, $offer, $product] = $this->scaffold();

        $first = CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'purchase_price' => 2,
            'sale_price' => 3,
            'tax_rate' => 0,
        ]);
        CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'purchase_price' => 4,
            'sale_price' => 5,
            'tax_rate' => 10,
        ]);

        $offer->refresh();
        $this->assertSame('13.0000', $offer->subtotal);
        $this->assertSame('1.0000', $offer->tax_total);
        $this->assertSame('14.0000', $offer->total);

        $first->delete();

        $offer->refresh();
        $this->assertSame('10.0000', $offer->subtotal);
        $this->assertSame('1.0000', $offer->tax_total);
        $this->assertSame('11.0000', $offer->total);
    }

    /**
     * @return array{0: Tenant, 1: CustomerOffer, 2: Product}
     */
    private function scaffold(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Product A',
        ]);
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'draft',
        ]);

        return [$tenant, $offer, $product];
    }
}
