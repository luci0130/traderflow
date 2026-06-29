<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSalesAgent() ?? false;
    }

    /**
     * @return array{
     *     customer_offers_sent: int,
     *     customer_offers_accepted: int,
     *     sales_orders: int,
     *     revenue: float,
     *     margin: float
     * }
     */
    public function metrics(?CarbonInterface $monthStart = null): array
    {
        $monthStart ??= now()->startOfMonth();
        $scope = app(DashboardScope::class);

        return [
            'customer_offers_sent' => $scope->applyTo(CustomerOffer::query())
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', $monthStart)
                ->count(),
            'customer_offers_accepted' => $scope->applyTo(CustomerOffer::query())
                ->where('status', 'accepted')
                ->where('updated_at', '>=', $monthStart)
                ->count(),
            'sales_orders' => $scope->applyTo(SalesOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'revenue' => (float) $scope->applyTo(SalesOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->sum('total'),
            'margin' => (float) $scope->applyTo(SalesOrderItem::query(), 'sales_order_items.tenant_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
                ->where('sales_orders.created_at', '>=', $monthStart)
                ->selectRaw('COALESCE(SUM((sale_price - COALESCE(purchase_price, 0)) * COALESCE(quantity, 0)), 0) as margin')
                ->value('margin'),
        ];
    }

    protected function getStats(): array
    {
        $m = $this->metrics();

        return [
            Stat::make(__('Customer offers sent this month'), $m['customer_offers_sent']),
            Stat::make(__('Customer offers accepted this month'), $m['customer_offers_accepted']),
            Stat::make(__('Sales orders this month'), $m['sales_orders']),
            Stat::make(__('Estimated revenue this month'), number_format($m['revenue'], 2)),
            Stat::make(__('Estimated margin this month'), number_format($m['margin'], 2)),
        ];
    }
}
