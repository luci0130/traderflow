<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOfferAcceptorTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_creates_the_customer_order_and_one_supplier_order_per_chosen_supplier(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Mega Image']);
        $supplierOne = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma Unu SRL']);
        $supplierTwo = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma Doi SRL']);

        $apple = Product::create(['tenant_id' => $tenant->id, 'name' => 'Mere']);
        $pear = Product::create(['tenant_id' => $tenant->id, 'name' => 'Pere']);
        $plum = Product::create(['tenant_id' => $tenant->id, 'name' => 'Prune']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        // Desired quantities (120) are deliberately larger than what gets secured so
        // the test proves the orders use the secured quantity, not the desired one.
        $appleItem = CustomerOfferItem::create([
            'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $apple->id,
            'quantity' => 120, 'purchase_price' => 2, 'sale_price' => 3, 'tax_rate' => 0, 'line_total' => 360,
        ]);
        $pearItem = CustomerOfferItem::create([
            'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $pear->id,
            'quantity' => 120, 'purchase_price' => 3, 'sale_price' => 5, 'tax_rate' => 0, 'line_total' => 600,
        ]);
        $plumItem = CustomerOfferItem::create([
            'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $plum->id,
            'quantity' => 120, 'purchase_price' => 4, 'sale_price' => 6, 'tax_rate' => 0, 'line_total' => 720,
        ]);

        // Apple is split across both suppliers (60 + 40 secured).
        $appleItem->suppliers()->createMany([
            ['supplier_id' => $supplierOne->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 2, 'currency' => 'RON', 'secured_quantity' => 60],
            ['supplier_id' => $supplierTwo->id, 'priority' => 2, 'include_in_order' => true, 'landed_cost' => 2.5, 'currency' => 'RON', 'secured_quantity' => 40],
        ]);
        // Pear from supplier one only.
        $pearItem->suppliers()->create(['supplier_id' => $supplierOne->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 3, 'currency' => 'RON', 'secured_quantity' => 50]);
        // Plum from supplier two; supplier one is included but secured nothing (skipped)
        // and a third row is excluded from the order entirely (skipped).
        $plumItem->suppliers()->createMany([
            ['supplier_id' => $supplierTwo->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 4, 'currency' => 'RON', 'secured_quantity' => 80],
            ['supplier_id' => $supplierOne->id, 'priority' => 2, 'include_in_order' => true, 'landed_cost' => 4, 'currency' => 'RON', 'secured_quantity' => 0],
            ['supplier_id' => $supplierOne->id, 'priority' => 3, 'include_in_order' => false, 'landed_cost' => 4, 'currency' => 'RON', 'secured_quantity' => 30],
        ]);

        $salesOrder = app(CustomerOfferAcceptor::class)->accept($offer);

        // Offer is accepted and converted to one sales order.
        $this->assertSame('accepted', $offer->refresh()->status);
        $this->assertInstanceOf(SalesOrder::class, $salesOrder);
        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertCount(3, $salesOrder->items);

        // Sales-order lines carry the total secured quantity, not the desired 120.
        $this->assertSame('100.0000', $salesOrder->items->firstWhere('product_id', $apple->id)->quantity);
        $this->assertSame('50.0000', $salesOrder->items->firstWhere('product_id', $pear->id)->quantity);
        $this->assertSame('80.0000', $salesOrder->items->firstWhere('product_id', $plum->id)->quantity);
        // 100*3 + 50*5 + 80*6 = 1030.
        $this->assertSame('1030.0000', $salesOrder->total);

        // One supplier order per supplier chosen for the order.
        $this->assertSame(2, SupplierOrder::query()->count());

        $orderOne = SupplierOrder::query()->where('supplier_id', $supplierOne->id)->first();
        $this->assertNotNull($orderOne);
        $this->assertSame($offer->id, $orderOne->customer_offer_id);
        $this->assertCount(2, $orderOne->items); // apple + pear
        $this->assertSame('60.0000', $orderOne->items->firstWhere('product_id', $apple->id)->quantity);
        $this->assertSame('50.0000', $orderOne->items->firstWhere('product_id', $pear->id)->quantity);
        // 60*2 + 50*3 = 270.
        $this->assertSame('270.0000', $orderOne->total);

        $orderTwo = SupplierOrder::query()->where('supplier_id', $supplierTwo->id)->first();
        $this->assertCount(2, $orderTwo->items); // apple + plum
        $this->assertSame('40.0000', $orderTwo->items->firstWhere('product_id', $apple->id)->quantity);
        $this->assertSame('80.0000', $orderTwo->items->firstWhere('product_id', $plum->id)->quantity);
        // 40*2.5 + 80*4 = 420.
        $this->assertSame('420.0000', $orderTwo->total);
    }

    public function test_accepting_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Client']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Mere']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $item = CustomerOfferItem::create(['tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 3, 'tax_rate' => 0, 'line_total' => 30]);
        $item->suppliers()->create(['supplier_id' => $supplier->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 2, 'currency' => 'RON', 'secured_quantity' => 10]);

        $first = app(CustomerOfferAcceptor::class)->accept($offer);
        $second = app(CustomerOfferAcceptor::class)->accept($offer->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertSame(1, SupplierOrder::query()->count());
    }
}
