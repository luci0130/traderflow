<?php

namespace App\Modules\MarketComparison\Queries;

use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupplierCostResolver;
use App\Modules\Producers\Models\SupplierProduct;
use App\Support\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the buy side of the market comparison: all active supplier products
 * mapped to a canonical product, ranked by landed cost (unit price plus the
 * resolved sourcing costs), cheapest first.
 */
class SupplierBestPriceQuery
{
    public function __construct(private SupplierCostResolver $costResolver, private ExchangeRate $exchangeRate) {}

    public function bestFor(CanonicalProduct $canonicalProduct): ?SupplierPriceCandidate
    {
        return $this->candidatesFor($canonicalProduct)->first();
    }

    /**
     * @return Collection<int, SupplierPriceCandidate>
     */
    public function candidatesFor(CanonicalProduct $canonicalProduct, ?CarbonInterface $createdFrom = null, ?CarbonInterface $createdBefore = null): Collection
    {
        return $canonicalProduct->supplierProducts()
            ->with(['producer' => fn ($query) => $query->withoutGlobalScopes(), 'costOverride'])
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', today());
            })
            ->whereNotNull('unit_price')
            ->when($createdFrom, fn (Builder $query): Builder => $query->where('supplier_products.created_at', '>=', $createdFrom))
            ->when($createdBefore, fn (Builder $query): Builder => $query->where('supplier_products.created_at', '<', $createdBefore))
            ->get()
            ->map(fn (SupplierProduct $product): ?SupplierPriceCandidate => $this->toCandidate($product))
            ->filter()
            ->sortBy(fn (SupplierPriceCandidate $candidate): array => [
                $this->exchangeRate->convert($candidate->landedCost, $candidate->currency, 'RON') ?? INF,
                $this->exchangeRate->convert($candidate->unitPrice, $candidate->currency, 'RON') ?? INF,
                $candidate->currency,
                $candidate->landedCost,
                $candidate->unitPrice,
            ])
            ->values();
    }

    private function toCandidate(SupplierProduct $product): ?SupplierPriceCandidate
    {
        $costs = $this->costResolver->resolve($product);
        $unitPrice = (float) $product->unit_price;
        $currency = $product->currency ?? 'EUR';
        $additionalCost = $this->exchangeRate->convert($costs->additionalCostPerUnit(), $costs->currency, $currency);

        if ($additionalCost === null) {
            return null;
        }

        return new SupplierPriceCandidate(
            supplierProduct: $product,
            supplierName: $product->producer?->name,
            countryOfOrigin: $product->country_of_origin,
            unitPrice: $unitPrice,
            landedCost: round($unitPrice + $additionalCost, 4),
            currency: $currency,
            quantityAvailable: $product->quantity_available !== null ? (float) $product->quantity_available : null,
            validUntil: $product->valid_until?->toDateString(),
            costs: $costs,
        );
    }
}
