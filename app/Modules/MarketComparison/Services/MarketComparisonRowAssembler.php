<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\MarketComparison\Data\MarketComparisonRow;
use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Support\Currency;
use App\Support\ExchangeRate;

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
        private ExchangeRate $exchangeRate,
    ) {}

    public function assemble(CanonicalProduct $canonicalProduct): MarketComparisonRow
    {
        $bestSupplier = $this->supplierBestPriceQuery->bestFor($canonicalProduct);
        $bestSupermarket = $this->supermarketBestPriceQuery->bestFor($canonicalProduct);

        [$margin, $marginPercent] = $this->margin($bestSupplier, $bestSupermarket);

        return new MarketComparisonRow(
            canonicalProduct: $canonicalProduct,
            bestSupplier: $bestSupplier,
            bestSupermarket: $bestSupermarket,
            margin: $margin,
            marginPercent: $marginPercent,
        );
    }

    /** @return array{0: float|null, 1: float|null} */
    public function margin(?SupplierPriceCandidate $supplier, ?SupermarketPriceCandidate $supermarket): array
    {
        if ($supplier === null || $supermarket === null) {
            return [null, null];
        }

        $currency = Currency::preferred();
        $buyPrice = $this->exchangeRate->convert($supplier->landedCost, $supplier->currency, $currency);
        $sellPrice = $this->exchangeRate->convert($supermarket->priceExclVat ?? $supermarket->grossPrice, $supermarket->currency, $currency);

        if ($buyPrice === null || $sellPrice === null) {
            return [null, null];
        }

        $margin = round($sellPrice - $buyPrice, 4);
        $percent = $buyPrice > 0 ? round(($margin / $buyPrice) * 100, 2) : null;

        return [$margin, $percent];
    }
}
