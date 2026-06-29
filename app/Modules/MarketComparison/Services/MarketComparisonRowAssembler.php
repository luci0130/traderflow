<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\MarketComparison\Data\MarketComparisonRow;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;

/**
 * Stitches the buy side (cheapest supplier) and the sell side (highest paying
 * supermarket) together for each canonical product, computing the profit margin
 * the trader could capture.
 */
class MarketComparisonRowAssembler
{
    public function __construct(
        private SupplierBestPriceQuery $supplierBestPriceQuery,
        private SupermarketBestPriceQuery $supermarketBestPriceQuery,
    ) {}

    public function assemble(CanonicalProduct $canonicalProduct): MarketComparisonRow
    {
        $bestSupplier = $this->supplierBestPriceQuery->bestFor($canonicalProduct);
        $bestSupermarket = $this->supermarketBestPriceQuery->bestFor($canonicalProduct);

        $margin = null;
        $marginPercent = null;

        if ($bestSupplier !== null && $bestSupermarket !== null) {
            $sellPrice = $bestSupermarket->priceExclVat ?? $bestSupermarket->grossPrice;
            $margin = round($sellPrice - $bestSupplier->landedCost, 4);

            if ($bestSupplier->landedCost > 0) {
                $marginPercent = round(($margin / $bestSupplier->landedCost) * 100, 2);
            }
        }

        return new MarketComparisonRow(
            canonicalProduct: $canonicalProduct,
            bestSupplier: $bestSupplier,
            bestSupermarket: $bestSupermarket,
            margin: $margin,
            marginPercent: $marginPercent,
        );
    }
}
