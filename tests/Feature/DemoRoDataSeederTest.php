<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Database\Seeders\DemoRoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemoRoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the seed fast; the production default is 60 customer offers/tenant.
        config(['demo.customer_offers_per_tenant' => 8]);
    }

    public function test_seeder_produces_the_expected_counts(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(3, User::query()->count());
        // Single global Legume + Fructe taxonomy (260 nodes), shared by all tenants.
        $this->assertSame(260, ProductCategory::query()->count());
        $this->assertSame(260, ProductCategory::query()->whereNull('tenant_id')->count());
        $this->assertSame(['Fructe', 'Legume'], ProductCategory::query()
            ->whereNull('parent_id')->orderBy('name')->pluck('name')->all());
        // One shared global catalog (tenant_id = null), not duplicated per tenant.
        $this->assertSame(5, Unit::query()->withoutGlobalScopes()->count());
        $this->assertSame(15, Product::query()->withoutGlobalScopes()->count());
        $this->assertSame(5, Supplier::query()->withoutGlobalScopes()->count());
        $this->assertSame(15, Product::query()->withoutGlobalScopes()->whereNull('tenant_id')->count());
        // Customers are now the supermarkets (global, tenant_id = null); no fictional tenant customers.
        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->whereNotNull('tenant_id')->count());
        $this->assertSame(13, Customer::query()->global()->count());

        // 8 customer offers per tenant (config override), both tenants seeded.
        $this->assertSame(16, CustomerOffer::query()->withoutGlobalScopes()->count());
        // Weekly inbound supplier offers (2 tenants x 5 suppliers x 3) plus the
        // per-supplier offers linked to each customer offer.
        $this->assertGreaterThanOrEqual(30, SupplierOffer::query()->withoutGlobalScopes()->count());
        // Accepted customer offers convert into sales orders and supplier orders.
        $this->assertGreaterThan(0, SalesOrder::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, SupplierOrder::query()->withoutGlobalScopes()->count());
    }

    public function test_offers_and_orders_exist_for_every_tenant(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        foreach (Tenant::query()->get() as $tenant) {
            $this->assertSame(8, CustomerOffer::query()->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThan(0, SalesOrder::query()->where('tenant_id', $tenant->id)->count());

            // Both linked (from accepted offers) and standalone supplier orders exist.
            $this->assertGreaterThan(0, SupplierOrder::query()->where('tenant_id', $tenant->id)->whereNotNull('customer_offer_id')->count());
            $this->assertGreaterThan(0, SupplierOrder::query()->where('tenant_id', $tenant->id)->whereNull('customer_offer_id')->count());
        }
    }

    public function test_documents_are_auto_numbered_from_the_tenant_sequences(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $tenant = Tenant::query()->where('name', 'Freshmarket București')->firstOrFail();

        $this->assertSame(0, CustomerOffer::query()->where('tenant_id', $tenant->id)->whereNull('offer_number')->count());
        $this->assertSame(0, SalesOrder::query()->where('tenant_id', $tenant->id)->whereNull('order_number')->count());
        $this->assertSame(0, SupplierOrder::query()->where('tenant_id', $tenant->id)->whereNull('order_number')->count());

        $this->assertStringStartsWith('OC-', (string) CustomerOffer::query()->where('tenant_id', $tenant->id)->value('offer_number'));
        $this->assertStringStartsWith('SO-', (string) SalesOrder::query()->where('tenant_id', $tenant->id)->value('order_number'));
        $this->assertStringStartsWith('CF-', (string) SupplierOrder::query()->where('tenant_id', $tenant->id)->value('order_number'));
        $this->assertStringStartsWith('OF-', (string) SupplierOffer::query()->where('tenant_id', $tenant->id)->value('offer_number'));
    }

    public function test_offers_are_spread_over_history_with_varied_statuses_and_totals(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $offers = CustomerOffer::query()->get();

        // Spread across more than a month (history window is 90 days).
        $oldest = $offers->min(fn (CustomerOffer $offer): \Carbon\CarbonInterface => $offer->offer_date);
        $this->assertGreaterThan(30, abs(Carbon::today()->diffInDays($oldest)));

        // A mix of statuses is seeded.
        $this->assertGreaterThan(1, $offers->pluck('status')->unique()->count());

        // Accepted offers carry computed totals (the observer ran).
        $accepted = CustomerOffer::query()->where('status', 'accepted')->first();
        $this->assertNotNull($accepted);
        $this->assertGreaterThan(0, (float) $accepted->total);
    }

    public function test_products_have_romanian_names_and_belong_to_romanian_categories(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $romanianProductNames = ['Morcovi România 1kg', 'Roșii cherry 500g', 'Ardei Kapia roșu 500g', 'Afine 125g'];
        foreach ($romanianProductNames as $name) {
            $this->assertTrue(
                Product::query()->withoutGlobalScopes()->where('name', $name)->exists(),
                "expected product '{$name}' to be seeded",
            );
        }

        $romanianCategoryNames = ['Rădăcinoase', 'Roșii cherry', 'Fructe tropicale', 'Pepene galben cantalup'];
        foreach ($romanianCategoryNames as $name) {
            $this->assertTrue(
                ProductCategory::query()->withoutGlobalScopes()->where('name', $name)->exists(),
                "expected category '{$name}' to be seeded",
            );
        }
    }

    public function test_categories_are_nested_by_prefix(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $leaf = ProductCategory::query()->withoutGlobalScopes()
            ->where('name', 'Roșii cherry roșii')->firstOrFail();

        $this->assertSame('Roșii cherry', $leaf->parent->name);
        $this->assertSame('Roșii', $leaf->parent->parent->name);
        $this->assertSame('Legume', $leaf->parent->parent->parent->name);
        $this->assertNull($leaf->parent->parent->parent->parent_id);
    }

    public function test_supplier_offer_items_vary_by_product_so_best_prices_has_data(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $varied = SupplierOfferItem::query()
            ->withoutGlobalScopes()
            ->selectRaw('product_id, COUNT(DISTINCT purchase_price) as variants')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT purchase_price) > 1')
            ->count();

        $this->assertGreaterThan(0, $varied);
    }

    public function test_image_downloads_are_skipped_in_the_test_environment(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $this->assertSame(0, Product::query()->withoutGlobalScopes()->whereNotNull('image_path')->count());
    }

    public function test_all_currencies_and_tenants_are_set_to_ron(): void
    {
        $this->seed(DemoRoDataSeeder::class);

        $this->assertSame(2, Tenant::query()->where('currency', 'RON')->count());
        $this->assertSame(16, CustomerOffer::query()->withoutGlobalScopes()->where('currency', 'RON')->count());
        $this->assertSame(0, SupplierOffer::query()->withoutGlobalScopes()->where('currency', '!=', 'RON')->count());
        $this->assertSame(0, SupplierOrder::query()->withoutGlobalScopes()->where('currency', '!=', 'RON')->count());
    }
}
