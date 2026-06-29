<?php

namespace App\Modules\MarketComparison\Queries;

use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the sell side of the market comparison: recent supermarket price
 * observations for the products mapped to a canonical product, ranked by
 * price, highest (best paying) first.
 */
class SupermarketBestPriceQuery
{
    public const DEFAULT_RECENCY_DAYS = 60;

    public function bestFor(CanonicalProduct $canonicalProduct, int $recencyDays = self::DEFAULT_RECENCY_DAYS): ?SupermarketPriceCandidate
    {
        return $this->candidatesFor($canonicalProduct, $recencyDays)->first();
    }

    /**
     * @return Collection<int, SupermarketPriceCandidate>
     */
    public function candidatesFor(CanonicalProduct $canonicalProduct, int $recencyDays = self::DEFAULT_RECENCY_DAYS): Collection
    {
        return SupermarketPrice::query()
            ->with(['supermarket', 'product'])
            ->whereHas(
                'product.canonicalProducts',
                fn (Builder $query): Builder => $query->whereKey($canonicalProduct->getKey()),
            )
            ->whereDate('observed_at', '>=', today()->subDays($recencyDays))
            ->orderByDesc('price')
            ->orderByDesc('observed_at')
            ->get()
            ->map(fn (SupermarketPrice $price): SupermarketPriceCandidate => $this->toCandidate($price));
    }

    private function toCandidate(SupermarketPrice $price): SupermarketPriceCandidate
    {
        return new SupermarketPriceCandidate(
            price: $price,
            supermarketName: $price->supermarket?->name,
            grossPrice: (float) $price->price,
            priceExclVat: $price->price_excl_vat,
            currency: $price->currency,
            observedAt: $price->observed_at?->toDateString(),
            isPromo: (bool) $price->is_promo,
            promoPrice: $price->promo_price !== null ? (float) $price->promo_price : null,
        );
    }
}
