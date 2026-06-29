<?php

namespace App\Modules\MarketComparison\Data;

use App\Modules\MarketComparison\Models\CanonicalProduct;

/**
 * One assembled row of the market comparison table: a canonical product with its
 * best (cheapest) supplier on the buy side and its best (highest paying)
 * supermarket on the sell side, plus the resulting profit margin.
 */
class MarketComparisonRow
{
    public function __construct(
        public CanonicalProduct $canonicalProduct,
        public ?SupplierPriceCandidate $bestSupplier,
        public ?SupermarketPriceCandidate $bestSupermarket,
        public ?float $margin,
        public ?float $marginPercent,
    ) {}

    public function hasMargin(): bool
    {
        return $this->margin !== null;
    }
}
