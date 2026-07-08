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

    public function test_sales_order_lines_use_the_total_secured_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $supplierOne = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Supplier A']);
        $supplierTwo = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Supplier B']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Apples']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'EUR', 'status' => 'accepted',
        ]);

        // Desired 100, but only 50 gets secured (30 + 20) across the chosen suppliers.
        $item = CustomerOfferItem::create([
            'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id,
            'quantity' => 100, 'purchase_price' => 4, 'sale_price' => 5, 'tax_rate' => 0,
        ]);
        $item->suppliers()->createMany([
            ['supplier_id' => $supplierOne->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 4, 'currency' => 'EUR', 'secured_quantity' => 30],
            ['supplier_id' => $supplierTwo->id, 'priority' => 2, 'include_in_order' => true, 'landed_cost' => 4, 'currency' => 'EUR', 'secured_quantity' => 20],
        ]);

        $salesOrder = app(CustomerOfferConverter::class)->convert($offer->refresh());

        $this->assertCount(1, $salesOrder->items);
        $this->assertSame('50.0000', $salesOrder->items->first()->quantity);
        $this->assertSame('250.0000', $salesOrder->items->first()->line_total); // 50 * 5
        $this->assertSame('250.0000', $salesOrder->subtotal);
        $this->assertSame('250.0000', $salesOrder->total);
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
