<?php

namespace App\Modules\Dashboard\Filament\Pages;

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
use App\Modules\Dashboard\Support\DashboardScope;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;

class TenantDashboard extends Dashboard
{
    /**
     * Every widget is listed here; each one self-gates by role via its
     * static canView(), so each role sees only the relevant subset.
     */
    public function getWidgets(): array
    {
        return [
            // Stats: super_admin sees the combined widget, agents their own.
            ThisMonthStatsWidget::class,
            SalesStatsWidget::class,
            PurchasingStatsWidget::class,

            // Needs attention.
            SupplierLeadsToConvertWidget::class,
            ExpiringCustomerOffersWidget::class,
            ExpiringSupplierOffersWidget::class,
            SalesOrdersToFulfillWidget::class,
            SupplierOrdersToFulfillWidget::class,

            // Latest records.
            LatestCustomerOffersWidget::class,
            LatestSalesOrdersWidget::class,
            LatestSupplierOffersWidget::class,
            LatestSupplierOrdersWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->isSuperAdmin() !== true) {
            return [];
        }

        $scope = app(DashboardScope::class);

        return [
            Action::make('toggleGlobalMode')
                ->label($scope->shouldShowGlobal() ? __('Show this tenant only') : __('Show global totals'))
                ->action(function (): void {
                    app(DashboardScope::class)->toggleGlobalMode();
                }),
        ];
    }
}
