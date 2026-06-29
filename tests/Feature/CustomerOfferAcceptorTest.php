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
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOfferAcceptorTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_creates_a_sales_order_and_one_supplier_order_per_supplier_offer(): void
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

        foreach ([$apple, $pear, $plum] as $product) {
            CustomerOfferItem::create([
                'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id,
                'quantity' => 100, 'purchase_price' => 2, 'sale_price' => 3, 'tax_rate' => 0, 'line_total' => 300,
            ]);
        }

        // Supplier offer 1 (two products), supplier offer 2 (one product), both linked.
        $offerOne = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplierOne->id, 'customer_offer_id' => $offer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        SupplierOfferItem::create(['tenant_id' => $tenant->id, 'supplier_offer_id' => $offerOne->id, 'product_id' => $apple->id, 'quantity' => 100, 'purchase_price' => 2, 'currency' => 'RON']);
        SupplierOfferItem::create(['tenant_id' => $tenant->id, 'supplier_offer_id' => $offerOne->id, 'product_id' => $pear->id, 'quantity' => 50, 'purchase_price' => 3, 'currency' => 'RON']);

        $offerTwo = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplierTwo->id, 'customer_offer_id' => $offer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        SupplierOfferItem::create(['tenant_id' => $tenant->id, 'supplier_offer_id' => $offerTwo->id, 'product_id' => $plum->id, 'quantity' => 80, 'purchase_price' => 4, 'currency' => 'RON']);

        $salesOrder = app(CustomerOfferAcceptor::class)->accept($offer);

        // Offer is accepted and converted to one sales order with its items.
        $this->assertSame('accepted', $offer->refresh()->status);
        $this->assertInstanceOf(SalesOrder::class, $salesOrder);
        $this->assertSame($offer->id, $salesOrder->customer_offer_id);
        $this->assertCount(3, $salesOrder->items);
        $this->assertSame(1, SalesOrder::query()->count());

        // One supplier order per linked supplier offer.
        $this->assertSame(2, SupplierOrder::query()->count());

        $orderOne = SupplierOrder::query()->where('supplier_offer_id', $offerOne->id)->first();
        $this->assertNotNull($orderOne);
        $this->assertSame($supplierOne->id, $orderOne->supplier_id);
        $this->assertSame($offer->id, $orderOne->customer_offer_id);
        $this->assertCount(2, $orderOne->items);
        // 100*2 + 50*3 = 350.
        $this->assertSame('350.0000', $orderOne->total);

        $orderTwo = SupplierOrder::query()->where('supplier_offer_id', $offerTwo->id)->first();
        $this->assertCount(1, $orderTwo->items);
        $this->assertSame('320.0000', $orderTwo->total); // 80*4

        // Supplier offers are marked approved once ordered.
        $this->assertSame('approved', $offerOne->refresh()->status);
        $this->assertSame('approved', $offerTwo->refresh()->status);
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
        CustomerOfferItem::create(['tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 3, 'tax_rate' => 0, 'line_total' => 30]);

        $supplierOffer = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $offer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        SupplierOfferItem::create(['tenant_id' => $tenant->id, 'supplier_offer_id' => $supplierOffer->id, 'product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 2, 'currency' => 'RON']);

        $first = app(CustomerOfferAcceptor::class)->accept($offer);
        $second = app(CustomerOfferAcceptor::class)->accept($offer->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertSame(1, SupplierOrder::query()->count());
    }
}
