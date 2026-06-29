<?php

namespace App\Modules\MarketComparison\Data;

use App\Modules\MarketComparison\Models\SupplierCostDefault;

/**
 * Resolved sourcing costs for a supplier product, after merging the per-product
 * overrides with the supplier-level defaults. Packaging, transport and commission
 * are absolute amounts per unit; `costBasis` controls how the profit margin is
 * applied (absolute per unit or a percentage of the landed cost).
 */
class SupplierCostBreakdown
{
    public function __construct(
        public float $packagingCost,
        public float $transportCost,
        public float $commission,
        public float $profitMargin,
        public string $costBasis,
        public string $currency,
    ) {}

    public function additionalCostPerUnit(): float
    {
        return round($this->packagingCost + $this->transportCost + $this->commission, 4);
    }

    /**
     * The full purchase-side cost per unit: supplier price plus all sourcing costs.
     */
    public function landedCost(float $unitPrice): float
    {
        return round($unitPrice + $this->additionalCostPerUnit(), 4);
    }

    /**
     * The landed cost with the configured profit margin applied on top.
     */
    public function targetSalePrice(float $unitPrice): float
    {
        $landedCost = $this->landedCost($unitPrice);

        if ($this->costBasis === SupplierCostDefault::COST_BASIS_PERCENT) {
            return round($landedCost * (1 + ($this->profitMargin / 100)), 4);
        }

        return round($landedCost + $this->profitMargin, 4);
    }
}
