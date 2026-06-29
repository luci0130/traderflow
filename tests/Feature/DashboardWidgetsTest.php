<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Dashboard\Filament\Pages\TenantDashboard;
use App\Modules\Dashboard\Filament\Widgets\ThisMonthStatsWidget;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_is_registered_in_the_admin_panel(): void
    {
        $this->assertTrue(class_exists(TenantDashboard::class));
        $this->assertTrue(Route::has('filament.admin.pages.tenant-dashboard'));
    }

    public function test_stats_widget_metrics_only_include_active_tenant_data(): void
    {
        [$tenantA, $userA, $tenantB] = $this->scaffoldTwoTenantsWithData();

        $this->actingAs($userA);
        session(['tenant_id' => $tenantA->id]);
        Filament::setTenant($tenantA);

        $metrics = (new ThisMonthStatsWidget)->metrics();

        $this->assertSame(1, $metrics['supplier_offers_received'], 'supplier offers');
        $this->assertSame(1, $metrics['customer_offers_sent'], 'customer offers sent');
        $this->assertSame(1, $metrics['sales_orders'], 'sales orders');
        $this->assertSame(100.0, $metrics['sales_value']);
        $this->assertSame(20.0, $metrics['estimated_profit']);
        $this->assertSame(1, $metrics['new_suppliers'], 'new suppliers');

        $this->assertGreaterThanOrEqual(2, SupplierOffer::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThanOrEqual(2, SalesOrder::query()->withoutGlobalScopes()->count());
    }

    public function test_super_admin_in_global_mode_aggregates_across_tenants(): void
    {
        [$tenantA, , $tenantB] = $this->scaffoldTwoTenantsWithData();

        $superAdmin = User::factory()->create();
        $this->actingAs($superAdmin);
        Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenantA->id]);
        setPermissionsTeamId($tenantA->id);
        $superAdmin->assignRole('super_admin');

        session([DashboardScope::SESSION_KEY => true]);

        $metrics = (new ThisMonthStatsWidget)->metrics();

        $this->assertSame(2, $metrics['supplier_offers_received']);
        $this->assertSame(2, $metrics['customer_offers_sent']);
        $this->assertSame(2, $metrics['sales_orders']);
        $this->assertSame(300.0, $metrics['sales_value']);
        $this->assertSame(70.0, $metrics['estimated_profit']);
    }

    public function test_super_admin_outside_global_mode_sees_only_selected_tenant(): void
    {
        [$tenantA, , $tenantB] = $this->scaffoldTwoTenantsWithData();

        $superAdmin = User::factory()->create();
        $this->actingAs($superAdmin);
        Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenantB->id]);
        setPermissionsTeamId($tenantB->id);
        $superAdmin->assignRole('super_admin');

        session(['tenant_id' => $tenantB->id]);
        Filament::setTenant($tenantB);

        $metrics = (new ThisMonthStatsWidget)->metrics();

        $this->assertSame(1, $metrics['sales_orders']);
        $this->assertSame(200.0, $metrics['sales_value']);
        $this->assertSame(50.0, $metrics['estimated_profit']);
    }

    public function test_scope_helper_returns_null_in_global_mode_and_tenant_id_otherwise(): void
    {
        $tenant = Tenant::create(['name' => 'T']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);

        $scope = new DashboardScope;

        $this->assertSame($tenant->id, $scope->tenantId());
        $this->assertFalse($scope->shouldShowGlobal());

        Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('super_admin');
        session()->forget('tenant_id');
        session([DashboardScope::SESSION_KEY => true]);

        $this->assertNull($scope->tenantId());
        $this->assertTrue($scope->shouldShowGlobal());
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Tenant}
     */
    private function scaffoldTwoTenantsWithData(): array
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        $userA = User::factory()->create();
        $tenantA->users()->attach($userA);

        $this->seedTenant($tenantA, salePrice: 50, purchasePrice: 40, quantity: 2, orderTotal: 100);
        $this->seedTenant($tenantB, salePrice: 60, purchasePrice: 35, quantity: 2, orderTotal: 200);

        return [$tenantA, $userA, $tenantB];
    }

    private function seedTenant(Tenant $tenant, float $salePrice, float $purchasePrice, int $quantity, float $orderTotal): void
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Cust '.$tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Sup '.$tenant->id]);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Prod '.$tenant->id]);

        SupplierOffer::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'status' => 'received',
        ]);

        CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'accepted',
        ]);

        $salesOrder = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'draft',
            'total' => $orderTotal,
        ]);

        SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
        ]);
    }
}
