<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferConverter;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CustomerOfferConverterTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_offer_converts_into_sales_order_with_copied_items(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Supplier A']);
        $unit = Unit::create(['tenant_id' => $tenant->id, 'name' => 'Kilogram', 'symbol' => 'kg']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Apples']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'accepted',
            'notes' => 'Initial offer notes',
        ]);

        CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'quantity' => 3,
            'purchase_price' => 4,
            'sale_price' => 5,
            'tax_rate' => 10,
            'notes' => 'Line note',
        ]);

        $offer->refresh();

        $salesOrder = app(CustomerOfferConverter::class)->convert($offer);

        $this->assertSame($offer->id, $salesOrder->customer_offer_id);
        $this->assertSame($customer->id, $salesOrder->customer_id);
        $this->assertSame('draft', $salesOrder->status);
        $this->assertSame('15.0000', $salesOrder->subtotal);
        $this->assertSame('1.5000', $salesOrder->tax_total);
        $this->assertSame('16.5000', $salesOrder->total);
        $this->assertSame('Initial offer notes', $salesOrder->notes);

        $this->assertCount(1, $salesOrder->items);
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'quantity' => 3,
            'purchase_price' => 4,
            'sale_price' => 5,
            'margin_value' => 1,
            'margin_percent' => 25,
            'line_total' => 15,
            'notes' => 'Line note',
        ]);
    }

    public function test_offers_that_are_not_accepted_cannot_be_converted(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'C']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'sent',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only accepted customer offers can be converted');

        app(CustomerOfferConverter::class)->convert($offer);

        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_a_customer_offer_cannot_be_converted_twice(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'C']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'accepted',
        ]);
        $offer->refresh();

        $converter = app(CustomerOfferConverter::class);
        $converter->convert($offer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A sales order already exists');

        $converter->convert($offer);

        $this->assertSame(1, SalesOrder::query()->where('customer_offer_id', $offer->id)->count());
    }
}
