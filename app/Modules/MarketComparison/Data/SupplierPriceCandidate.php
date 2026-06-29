<?php

namespace App\Modules\MarketComparison\Data;

use App\Modules\Producers\Models\SupplierProduct;

/**
 * One supplier product priced against a canonical product, with its resolved
 * sourcing costs and the resulting landed cost per unit.
 */
class SupplierPriceCandidate
{
    public function __construct(
        public SupplierProduct $supplierProduct,
        public ?string $supplierName,
        public ?string $countryOfOrigin,
        public float $unitPrice,
        public float $landedCost,
        public string $currency,
        public ?float $quantityAvailable,
        public ?string $validUntil,
        public SupplierCostBreakdown $costs,
    ) {}
}
