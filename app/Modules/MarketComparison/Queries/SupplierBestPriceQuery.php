<?php

namespace App\Modules\MarketComparison\Queries;

use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupplierCostResolver;
use App\Modules\Producers\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the buy side of the market comparison: all active supplier products
 * mapped to a canonical product, ranked by landed cost (unit price plus the
 * resolved sourcing costs), cheapest first.
 */
class SupplierBestPriceQuery
{
    public function __construct(private SupplierCostResolver $costResolver) {}

    public function bestFor(CanonicalProduct $canonicalProduct): ?SupplierPriceCandidate
    {
        return $this->candidatesFor($canonicalProduct)->first();
    }

    /**
     * @return Collection<int, SupplierPriceCandidate>
     */
    public function candidatesFor(CanonicalProduct $canonicalProduct): Collection
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
            ->get()
            ->map(fn (SupplierProduct $product): SupplierPriceCandidate => $this->toCandidate($product))
            ->sortBy([
                ['landedCost', 'asc'],
                ['unitPrice', 'asc'],
            ])
            ->values();
    }

    private function toCandidate(SupplierProduct $product): SupplierPriceCandidate
    {
        $costs = $this->costResolver->resolve($product);
        $unitPrice = (float) $product->unit_price;

        return new SupplierPriceCandidate(
            supplierProduct: $product,
            supplierName: $product->producer?->name,
            countryOfOrigin: $product->country_of_origin,
            unitPrice: $unitPrice,
            landedCost: $costs->landedCost($unitPrice),
            currency: $costs->currency,
            quantityAvailable: $product->quantity_available !== null ? (float) $product->quantity_available : null,
            validUntil: $product->valid_until?->toDateString(),
            costs: $costs,
        );
    }
}
