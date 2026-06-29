<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\Producers\Models\Producer;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ThisMonthStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array{
     *     customer_offers_sent: int,
     *     sales_orders: int,
     *     sales_value: float,
     *     supplier_offers_received: int,
     *     supplier_orders: int,
     *     purchasing_value: float,
     *     estimated_revenue: float,
     *     estimated_profit: float,
     *     profit: float,
     *     new_suppliers: int,
     *     new_producers: int
     * }
     */
    public function metrics(?CarbonInterface $monthStart = null): array
    {
        $monthStart ??= now()->startOfMonth();
        $scope = app(DashboardScope::class);

        return [
            // Sales pipeline.
            'customer_offers_sent' => $scope->applyTo(CustomerOffer::query())
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', $monthStart)
                ->count(),
            'sales_orders' => $scope->applyTo(SalesOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'sales_value' => (float) $scope->applyTo(SalesOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->sum('total'),

            // Purchasing pipeline.
            'supplier_offers_received' => $scope->applyTo(SupplierOffer::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'supplier_orders' => $scope->applyTo(SupplierOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'purchasing_value' => (float) $scope->applyTo(SupplierOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->sum('total'),

            // Financials.
            'estimated_revenue' => (float) $scope->applyTo(CustomerOffer::query())
                ->where('status', 'accepted')
                ->where('updated_at', '>=', $monthStart)
                ->sum('total'),
            'estimated_profit' => (float) $scope->applyTo(SalesOrderItem::query(), 'sales_order_items.tenant_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
                ->where('sales_orders.created_at', '>=', $monthStart)
                ->selectRaw('COALESCE(SUM((sale_price - COALESCE(purchase_price, 0)) * COALESCE(quantity, 0)), 0) as profit')
                ->value('profit'),
            // Realized profit: only finalized sales orders, all-time.
            'profit' => (float) $scope->applyTo(SalesOrderItem::query(), 'sales_order_items.tenant_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
                ->whereIn('sales_orders.status', ['delivered', 'invoiced', 'paid'])
                ->selectRaw('COALESCE(SUM((sale_price - COALESCE(purchase_price, 0)) * COALESCE(quantity, 0)), 0) as profit')
                ->value('profit'),

            // New entities this month.
            'new_suppliers' => $scope->applyTo(Supplier::query()->where('is_producer', false))
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'new_producers' => Producer::query()
                ->where('created_at', '>=', $monthStart)
                ->count(),
        ];
    }

    protected function getStats(): array
    {
        $m = $this->metrics();

        return [
            Stat::make(__('Customer offers sent this month'), $m['customer_offers_sent']),
            Stat::make(__('Sales orders this month'), $m['sales_orders']),
            Stat::make(__('Sales value this month'), number_format($m['sales_value'], 2)),

            Stat::make(__('Supplier offers received this month'), $m['supplier_offers_received']),
            Stat::make(__('Supplier orders this month'), $m['supplier_orders']),
            Stat::make(__('Purchasing value this month'), number_format($m['purchasing_value'], 2)),

            Stat::make(__('Estimated revenue this month'), number_format($m['estimated_revenue'], 2)),
            Stat::make(__('Estimated profit this month'), number_format($m['estimated_profit'], 2)),
            Stat::make(__('Profit'), number_format($m['profit'], 2)),

            Stat::make(__('New suppliers'), $m['new_suppliers']),
            Stat::make(__('New producers'), $m['new_producers']),
        ];
    }
}
