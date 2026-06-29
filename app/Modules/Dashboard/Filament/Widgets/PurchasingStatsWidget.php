<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\Suppliers\Models\SupplierLead;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PurchasingStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isPurchasingAgent() ?? false;
    }

    /**
     * @return array{
     *     supplier_offers_received: int,
     *     supplier_offers_processed: int,
     *     supplier_orders: int,
     *     purchasing_value: float,
     *     supplier_leads_open: int
     * }
     */
    public function metrics(?CarbonInterface $monthStart = null): array
    {
        $monthStart ??= now()->startOfMonth();
        $scope = app(DashboardScope::class);

        return [
            'supplier_offers_received' => $scope->applyTo(SupplierOffer::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'supplier_offers_processed' => $scope->applyTo(SupplierOffer::query())
                ->whereIn('status', ['processed', 'approved'])
                ->where('updated_at', '>=', $monthStart)
                ->count(),
            'supplier_orders' => $scope->applyTo(SupplierOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'purchasing_value' => (float) $scope->applyTo(SupplierOrder::query())
                ->where('created_at', '>=', $monthStart)
                ->sum('total'),
            // Supplier leads are global (no tenant scope).
            'supplier_leads_open' => SupplierLead::query()
                ->whereNull('converted_supplier_id')
                ->count(),
        ];
    }

    protected function getStats(): array
    {
        $m = $this->metrics();

        return [
            Stat::make(__('Supplier offers received this month'), $m['supplier_offers_received']),
            Stat::make(__('Supplier offers processed this month'), $m['supplier_offers_processed']),
            Stat::make(__('Supplier orders this month'), $m['supplier_orders']),
            Stat::make(__('Purchasing value this month'), number_format($m['purchasing_value'], 2)),
            Stat::make(__('Supplier leads to convert'), $m['supplier_leads_open']),
        ];
    }
}
