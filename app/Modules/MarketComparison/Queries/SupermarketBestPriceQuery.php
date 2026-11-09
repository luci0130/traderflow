<?php

namespace App\Modules\MarketComparison\Queries;

use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Support\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the sell side of the market comparison: recent supermarket price
 * observations for the products mapped to a canonical product, reduced to the
 * latest observation per supermarket and ranked by price, highest (best
 * paying) first.
 */
class SupermarketBestPriceQuery
{
    public const DEFAULT_RECENCY_DAYS = 60;

    public function __construct(private ExchangeRate $exchangeRate) {}

    public function bestFor(CanonicalProduct $canonicalProduct, int $recencyDays = self::DEFAULT_RECENCY_DAYS): ?SupermarketPriceCandidate
    {
        return $this->candidatesFor($canonicalProduct, $recencyDays)->first();
    }

    /**
     * @return Collection<int, SupermarketPriceCandidate>
     */
    public function candidatesFor(CanonicalProduct $canonicalProduct, int $recencyDays = self::DEFAULT_RECENCY_DAYS, ?CarbonInterface $createdFrom = null, ?CarbonInterface $createdBefore = null): Collection
    {
        return SupermarketPrice::query()
            ->with(['supermarket', 'product'])
            ->whereHas(
                'product.canonicalProducts',
                fn (Builder $query): Builder => $query->whereKey($canonicalProduct->getKey()),
            )
            ->whereDate('observed_at', '>=', today()->subDays($recencyDays))
            ->when($createdFrom, fn (Builder $query): Builder => $query->where('created_at', '>=', $createdFrom))
            ->when($createdBefore, fn (Builder $query): Builder => $query->where('created_at', '<', $createdBefore))
            ->get()
            ->groupBy(fn (SupermarketPrice $price): string => $this->supermarketKey($price))
            ->map(fn (Collection $prices): SupermarketPrice => $prices->sort($this->latestFirst(...))->first())
            ->sortByDesc(fn (SupermarketPrice $price): float => $this->exchangeRate->convert((float) $price->price, $price->currency, 'RON') ?? -INF)
            ->values()
            ->map(fn (SupermarketPrice $price): SupermarketPriceCandidate => $this->toCandidate($price));
    }

    /**
     * Prices are grouped per supermarket so only its latest observation shows.
     * Orphan observations (no supermarket) keep their own group.
     */
    private function supermarketKey(SupermarketPrice $price): string
    {
        return $price->supermarket_id !== null
            ? 'supermarket:'.$price->supermarket_id
            : 'price:'.$price->getKey();
    }

    /**
     * Most recently observed first, falling back to the most recently recorded
     * row when two observations share the same date.
     */
    private function latestFirst(SupermarketPrice $a, SupermarketPrice $b): int
    {
        return [$b->observed_at?->getTimestamp() ?? 0, $b->getKey()]
            <=> [$a->observed_at?->getTimestamp() ?? 0, $a->getKey()];
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
