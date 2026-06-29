<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\MarketComparison\Data\SupplierCostBreakdown;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\Producers\Models\SupplierProduct;

/**
 * Resolves the sourcing costs for a supplier product: each cost field falls back
 * from the per-product override to the supplier default, then to zero.
 */
class SupplierCostResolver
{
    public function resolve(SupplierProduct $product): SupplierCostBreakdown
    {
        $product->loadMissing('costOverride');

        $override = $product->costOverride;

        $default = SupplierCostDefault::query()
            ->where('supplier_id', $product->producer_id)
            ->first();

        return new SupplierCostBreakdown(
            packagingCost: (float) ($override?->packaging_cost ?? $default?->packaging_cost ?? 0),
            transportCost: (float) ($override?->transport_cost ?? $default?->transport_cost ?? 0),
            commission: (float) ($override?->commission ?? $default?->commission ?? 0),
            profitMargin: (float) ($override?->profit_margin ?? $default?->profit_margin ?? 0),
            costBasis: $default?->cost_basis ?? SupplierCostDefault::COST_BASIS_PER_UNIT,
            currency: $default?->currency ?? $product->currency ?? 'EUR',
        );
    }
}
