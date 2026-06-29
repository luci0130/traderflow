<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Dashboard\Filament\Widgets\ExpiringCustomerOffersWidget;
use App\Modules\Dashboard\Filament\Widgets\ExpiringSupplierOffersWidget;
use App\Modules\Dashboard\Filament\Widgets\LatestCustomerOffersWidget;
use App\Modules\Dashboard\Filament\Widgets\LatestSalesOrdersWidget;
use App\Modules\Dashboard\Filament\Widgets\LatestSupplierOffersWidget;
use App\Modules\Dashboard\Filament\Widgets\LatestSupplierOrdersWidget;
use App\Modules\Dashboard\Filament\Widgets\PurchasingStatsWidget;
use App\Modules\Dashboard\Filament\Widgets\SalesOrdersToFulfillWidget;
use App\Modules\Dashboard\Filament\Widgets\SalesStatsWidget;
use App\Modules\Dashboard\Filament\Widgets\SupplierLeadsToConvertWidget;
use App\Modules\Dashboard\Filament\Widgets\SupplierOrdersToFulfillWidget;
use App\Modules\Dashboard\Filament\Widgets\ThisMonthStatsWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class DashboardWidgetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Widgets visible to a sales agent (and super admin). */
    private const SALES_WIDGETS = [
        SalesStatsWidget::class,
        LatestCustomerOffersWidget::class,
        LatestSalesOrdersWidget::class,
        ExpiringCustomerOffersWidget::class,
        SalesOrdersToFulfillWidget::class,
    ];

    /** Widgets visible to a purchasing agent (and super admin). */
    private const PURCHASING_WIDGETS = [
        PurchasingStatsWidget::class,
        LatestSupplierOffersWidget::class,
        LatestSupplierOrdersWidget::class,
        ExpiringSupplierOffersWidget::class,
        SupplierOrdersToFulfillWidget::class,
        SupplierLeadsToConvertWidget::class,
    ];

    /** Widgets visible only to super admin (combined stats + the sales orders table). */
    private const SUPER_ONLY_WIDGETS = [
        ThisMonthStatsWidget::class,
    ];

    /** The full set the super admin sees (LatestSalesOrders is shared with sales). */
    private const SUPER_VISIBLE_WIDGETS = [
        ThisMonthStatsWidget::class,
        LatestSalesOrdersWidget::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        setPermissionsTeamId(null);

        foreach (['super_admin', 'sales_agent', 'purchasing_agent'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => null]);
        }
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user->refresh());

        return $user;
    }

    public function test_sales_agent_sees_only_sales_widgets(): void
    {
        $this->actingAsRole('sales_agent');

        foreach (self::SALES_WIDGETS as $widget) {
            $this->assertTrue($widget::canView(), "sales_agent should see {$widget}");
        }

        foreach ([...self::PURCHASING_WIDGETS, ...self::SUPER_ONLY_WIDGETS] as $widget) {
            $this->assertFalse($widget::canView(), "sales_agent should NOT see {$widget}");
        }
    }

    public function test_purchasing_agent_sees_only_purchasing_widgets(): void
    {
        $this->actingAsRole('purchasing_agent');

        foreach (self::PURCHASING_WIDGETS as $widget) {
            $this->assertTrue($widget::canView(), "purchasing_agent should see {$widget}");
        }

        foreach ([...self::SALES_WIDGETS, ...self::SUPER_ONLY_WIDGETS] as $widget) {
            $this->assertFalse($widget::canView(), "purchasing_agent should NOT see {$widget}");
        }
    }

    public function test_super_admin_sees_only_combined_stats_and_sales_orders(): void
    {
        $this->actingAsRole('super_admin');

        foreach (self::SUPER_VISIBLE_WIDGETS as $widget) {
            $this->assertTrue($widget::canView(), "super_admin should see {$widget}");
        }

        $hidden = array_diff(
            [...self::SALES_WIDGETS, ...self::PURCHASING_WIDGETS, ...self::SUPER_ONLY_WIDGETS],
            self::SUPER_VISIBLE_WIDGETS,
        );

        foreach ($hidden as $widget) {
            $this->assertFalse($widget::canView(), "super_admin should NOT see {$widget}");
        }
    }
}
