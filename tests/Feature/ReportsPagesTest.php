<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Reports\Filament\Pages\ProductProfitReport;
use App\Modules\Reports\Filament\Pages\SalesOrderProfitReport;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class ReportsPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticate a super-admin user against a tenant. The report pages are
     * restricted to super admins, while the tenant scope stays in effect for
     * the aggregated data.
     */
    private function actingAsTenantUser(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        session(['tenant_id' => $tenant->id]);

        setPermissionsTeamId($tenant->getKey());
        Permission::firstOrCreate(['name' => 'ViewAny:SalesOrder', 'guard_name' => 'web']);
        $user->assignRole(
            Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]),
        );

        return $tenant;
    }

    private function createOrderWithItem(Tenant $tenant, string $productName, array $itemAttributes = []): SalesOrder
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => $productName]);

        $order = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'order_number' => 'SO-'.$productName,
            'currency' => 'EUR',
            'status' => 'delivered',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        SalesOrderItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'purchase_price' => 0.80,
            'sale_price' => 1.20,
            'margin_value' => 0.40,
            'margin_percent' => 50,
            'line_total' => 120.00,
        ], $itemAttributes));

        return $order;
    }

    public function test_both_report_pages_are_registered_as_filament_routes(): void
    {
        $this->assertTrue(Route::has('filament.admin.pages.reports.sales-order-profit'));
        $this->assertTrue(Route::has('filament.admin.pages.reports.product-profit'));
    }

    public function test_sales_order_report_aggregates_revenue_cost_and_profit_per_order(): void
    {
        $tenant = $this->actingAsTenantUser();
        $order = $this->createOrderWithItem($tenant, 'Mere');

        $row = app(SalesOrderProfitReport::class)->getQuery()->find($order->id);

        // revenue = line_total 120, cost = 100 * 0.80 = 80, profit = 100 * 0.40 = 40
        $this->assertEquals(120.0, (float) $row->revenue_total);
        $this->assertEquals(80.0, (float) $row->cost_total);
        $this->assertEquals(40.0, (float) $row->profit_total);
    }

    public function test_sales_order_report_renders_through_livewire(): void
    {
        $tenant = $this->actingAsTenantUser();
        $this->createOrderWithItem($tenant, 'Mere');

        Livewire::test(SalesOrderProfitReport::class)
            ->assertSuccessful()
            ->assertSee('SO-Mere');
    }

    public function test_product_report_groups_quantity_and_profit_per_product(): void
    {
        $tenant = $this->actingAsTenantUser();
        // Two orders of the same product: 100 + 50 units sold.
        $this->createOrderWithItem($tenant, 'Mere', ['quantity' => 100]);
        $product = Product::query()->where('name', 'Mere')->first();
        $secondOrder = $this->createOrderWithItem($tenant, 'Pere');
        // Add a second line of "Mere" inside the Pere order to test grouping across orders.
        SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $secondOrder->id,
            'product_id' => $product->id,
            'quantity' => 50,
            'purchase_price' => 0.80,
            'sale_price' => 1.20,
            'margin_value' => 0.40,
            'margin_percent' => 50,
            'line_total' => 60.00,
        ]);

        $row = app(ProductProfitReport::class)->getQuery()->get()
            ->firstWhere('product_name', 'Mere');

        $this->assertSame('Mere', $row->product_name);
        $this->assertEquals(150.0, (float) $row->quantity_sold);
        $this->assertEquals(2, (int) $row->orders_count);
        // profit = 150 * 0.40 = 60
        $this->assertEquals(60.0, (float) $row->profit_total);
    }

    public function test_product_report_renders_through_livewire(): void
    {
        $tenant = $this->actingAsTenantUser();
        $this->createOrderWithItem($tenant, 'Mere');

        Livewire::test(ProductProfitReport::class)
            ->assertSuccessful()
            ->assertSee('Mere');
    }

    /**
     * The product report query is grouped by product_id. Filament otherwise
     * appends an automatic tie-breaker sort on the qualified primary key
     * (sales_order_items.id), which is an ungrouped column. SQLite tolerates
     * it, but Postgres rejects it as a GROUP BY violation and returns a 500.
     * Guard against the tie-breaker leaking back into the sorted query.
     */
    public function test_product_report_does_not_sort_by_ungrouped_primary_key(): void
    {
        $tenant = $this->actingAsTenantUser();
        $this->createOrderWithItem($tenant, 'Mere');

        $sql = Livewire::test(ProductProfitReport::class)
            ->instance()
            ->getFilteredSortedTableQuery()
            ->toSql();

        $this->assertStringContainsString('order by', strtolower($sql));
        $this->assertStringNotContainsString('sales_order_items"."id', $sql);
    }
}
