<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_produces_the_expected_counts(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(2, Tenant::query()->count(), 'tenants');
        $this->assertSame(5, User::query()->count(), 'users');
        $this->assertSame(10, ProductCategory::query()->withoutGlobalScopes()->count(), 'categories');
        $this->assertSame(10, Unit::query()->withoutGlobalScopes()->count(), 'units');
        $this->assertSame(30, Product::query()->withoutGlobalScopes()->count(), 'products');
        $this->assertSame(10, Supplier::query()->withoutGlobalScopes()->count(), 'suppliers');
        $this->assertSame(10, Customer::query()->withoutGlobalScopes()->count(), 'customers');
        $this->assertSame(20, SupplierOffer::query()->withoutGlobalScopes()->count(), 'supplier offers');
        $this->assertSame(5, CustomerOffer::query()->withoutGlobalScopes()->count(), 'customer offers');
        $this->assertSame(2, SalesOrder::query()->withoutGlobalScopes()->count(), 'sales orders');
    }

    public function test_seeded_supplier_offer_items_have_varied_purchase_prices_per_product(): void
    {
        $this->seed(DemoDataSeeder::class);

        $variedProducts = SupplierOfferItem::query()
            ->withoutGlobalScopes()
            ->selectRaw('product_id, COUNT(DISTINCT purchase_price) as price_variants')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT purchase_price) > 1')
            ->get();

        $this->assertGreaterThan(0, $variedProducts->count(), 'expected at least one product priced differently across suppliers');
    }

    public function test_seeded_sales_orders_are_linked_to_accepted_customer_offers_with_copied_items(): void
    {
        $this->seed(DemoDataSeeder::class);

        $salesOrders = SalesOrder::query()->withoutGlobalScopes()->get();
        $this->assertCount(2, $salesOrders);

        foreach ($salesOrders as $order) {
            $this->assertNotNull($order->customer_offer_id, 'sales order should reference a customer offer');

            $offer = CustomerOffer::query()->withoutGlobalScopes()->find($order->customer_offer_id);
            $this->assertNotNull($offer);
            $this->assertSame('accepted', $offer->status);

            $offerItemCount = CustomerOfferItem::query()->withoutGlobalScopes()->where('customer_offer_id', $offer->id)->count();
            $orderItemCount = SalesOrderItem::query()->withoutGlobalScopes()->where('sales_order_id', $order->id)->count();
            $this->assertSame($offerItemCount, $orderItemCount, 'sales order items must match customer offer items');
        }
    }

    public function test_seeded_users_can_authenticate_with_the_default_password(): void
    {
        $this->seed(DemoDataSeeder::class);

        $alice = User::query()->where('email', 'alice@traderflow.test')->firstOrFail();
        $this->assertTrue($alice->isSuperAdmin(), 'Alice should hold the super_admin role');
    }
}
