<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a draft customer (supermarket) offer from market comparison rows. The
 * buy side comes from each canonical product's cheapest supplier (landed cost),
 * the sell side either follows the best supermarket price or applies a margin
 * on top of the landed cost.
 *
 * The customer offer is the single offer entity: each line carries its ordered
 * list of prioritized suppliers (the buy/sourcing side) as
 * {@see CustomerOfferItemSupplier} rows, which
 * the purchasing agent later fills with landed cost and secured quantity.
 */
class SupermarketOfferBuilder
{
    public const SALE_FROM_SUPERMARKET = 'supermarket_price';

    public const SALE_FROM_PERCENTAGE = 'percentage';

    public const SALE_FROM_FIXED = 'fixed';

    public function __construct(
        private SupplierBestPriceQuery $supplierBestPriceQuery,
        private SupermarketBestPriceQuery $supermarketBestPriceQuery,
    ) {}

    /**
     * Builds a draft offer from the given canonical products. By default each
     * product uses its cheapest supplier and best supermarket price; passing
     * per-product overrides pins a hand-picked supplier (buy side) or
     * supermarket price (sell side) keyed by canonical product id.
     *
     * @param  Collection<int, CanonicalProduct>  $canonicalProducts
     * @param  array<string, mixed>  $data
     * @param  array<int, list<int>>  $supplierPriorities  canonicalProductId => ordered supplierProductIds (priority #1 first)
     * @param  array<int, int>  $supermarketOverrides  canonicalProductId => supermarketPriceId
     * @param  array<int, float|int|string|null>  $quantities  canonicalProductId => offered quantity (defaults to the supplier's available quantity)
     * @param  array<int, float|int|string|null>  $margins  canonicalProductId => per-product margin value (defaults to the offer-level margin)
     */
    public function build(
        Collection $canonicalProducts,
        array $data,
        ?int $tenantId,
        array $supplierPriorities = [],
        array $supermarketOverrides = [],
        array $quantities = [],
        array $margins = [],
    ): CustomerOffer {
        return DB::transaction(function () use ($canonicalProducts, $data, $tenantId, $supplierPriorities, $supermarketOverrides, $quantities, $margins): CustomerOffer {
            $customer = Customer::query()
                ->visibleToTenant($tenantId)
                ->findOrFail($data['customer_id']);

            $saleMode = $data['sale_mode'] ?? self::SALE_FROM_SUPERMARKET;
            $marginInput = (float) ($data['margin_value'] ?? 0);
            $currency = filled($data['currency'] ?? null) ? $data['currency'] : 'EUR';

            $customerOffer = CustomerOffer::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->getKey(),
                'offer_number' => $data['offer_number'] ?? null,
                'offer_date' => today(),
                'valid_until' => $data['valid_until'] ?? null,
                'currency' => $currency,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
            ]);

            foreach ($canonicalProducts as $canonicalProduct) {
                $priorityList = $supplierPriorities[$canonicalProduct->getKey()] ?? [];
                $orderedCandidates = $this->orderedSupplierCandidates($canonicalProduct, $priorityList);

                // Priority #1 (or the cheapest fallback) is the buy source that
                // seeds the offer line's purchase price and quantity.
                $supplierCandidate = $orderedCandidates[0] ?? null;

                if ($supplierCandidate === null) {
                    continue;
                }

                $supermarketCandidate = $this->resolveSupermarketCandidate(
                    $canonicalProduct,
                    $supermarketOverrides[$canonicalProduct->getKey()] ?? null,
                );

                // The quantity can be sourced across all prioritized suppliers, so
                // it is clamped to their combined availability, not just #1's.
                $quantity = $this->resolveQuantity(
                    $quantities[$canonicalProduct->getKey()] ?? null,
                    $this->combinedAvailability($orderedCandidates),
                );
                $product = $this->resolveTenantProduct($canonicalProduct, $tenantId);

                $item = $this->createItem(
                    $customerOffer,
                    $supplierCandidate,
                    $supermarketCandidate,
                    $product,
                    $saleMode,
                    $this->resolveMargin($margins[$canonicalProduct->getKey()] ?? null, $marginInput),
                    $tenantId,
                    $quantity,
                );

                $this->attachSuppliers($item, $orderedCandidates);
            }

            return $customerOffer->refresh();
        });
    }

    private function createItem(
        CustomerOffer $customerOffer,
        SupplierPriceCandidate $supplierCandidate,
        ?SupermarketPriceCandidate $supermarketCandidate,
        Product $product,
        string $saleMode,
        float $marginInput,
        ?int $tenantId,
        ?float $quantity,
    ): CustomerOfferItem {
        $purchasePrice = $supplierCandidate->landedCost;
        $salePrice = $this->resolveSalePrice($supplierCandidate, $supermarketCandidate, $saleMode, $marginInput);

        return CustomerOfferItem::create([
            'tenant_id' => $tenantId,
            'customer_offer_id' => $customerOffer->getKey(),
            'product_id' => $product->getKey(),
            'supplier_id' => $supplierCandidate->supplierProduct->producer_id,
            'supplier_product_id' => $supplierCandidate->supplierProduct->getKey(),
            'supplier_offer_item_id' => null,
            'unit_id' => null,
            'quantity' => $quantity,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'tax_rate' => 0,
        ]);
    }

    /**
     * Attach the ordered supplier candidates to the offer line as its buy-side
     * sourcing rows (priority 1..n). Landed cost and secured quantity are left
     * blank for the purchasing agent to fill in later.
     *
     * @param  list<SupplierPriceCandidate>  $candidates
     */
    private function attachSuppliers(CustomerOfferItem $item, array $candidates): void
    {
        foreach ($candidates as $index => $candidate) {
            $item->suppliers()->create([
                'supplier_id' => $candidate->supplierProduct->producer_id,
                'supplier_product_id' => $candidate->supplierProduct->getKey(),
                'priority' => $index + 1,
                'unit_price' => $candidate->unitPrice,
                'currency' => $candidate->currency,
                'quantity_available' => $candidate->quantityAvailable,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * The offered quantity for an item: the user-supplied value (clamped to the
     * suppliers' combined available quantity) when given, otherwise the full
     * available quantity. A blank override falls back to that availability.
     */
    private function resolveQuantity(float|int|string|null $override, ?float $available): ?float
    {
        if ($override === null || $override === '') {
            return $available;
        }

        $quantity = max(0.0, (float) $override);

        if ($available !== null) {
            $quantity = min($quantity, $available);
        }

        return $quantity;
    }

    /**
     * The combined available quantity across the given supplier candidates, or
     * null when none of them report an availability (treated as uncapped).
     *
     * @param  list<SupplierPriceCandidate>  $candidates
     */
    private function combinedAvailability(array $candidates): ?float
    {
        $availabilities = array_values(array_filter(
            array_map(fn (SupplierPriceCandidate $candidate): ?float => $candidate->quantityAvailable, $candidates),
            fn (?float $value): bool => $value !== null,
        ));

        return $availabilities !== [] ? array_sum($availabilities) : null;
    }

    /**
     * The margin value for an item: the per-product override when given,
     * otherwise the offer-level margin. A blank override falls back to the
     * offer-level margin.
     */
    private function resolveMargin(float|int|string|null $override, float $offerMargin): float
    {
        if ($override === null || $override === '') {
            return $offerMargin;
        }

        return max(0.0, (float) $override);
    }

    /**
     * The ordered supplier candidates for a product's prioritized list. Each
     * id is resolved to its active candidate (dropping any that went inactive);
     * an empty list falls back to the single cheapest supplier so a product
     * always yields at least one supplier row.
     *
     * @param  list<int>  $priorityList
     * @return list<SupplierPriceCandidate>
     */
    private function orderedSupplierCandidates(CanonicalProduct $canonicalProduct, array $priorityList): array
    {
        $candidates = $this->supplierBestPriceQuery->candidatesFor($canonicalProduct);

        if ($priorityList === []) {
            $cheapest = $candidates->first();

            return $cheapest !== null ? [$cheapest] : [];
        }

        $ordered = [];

        foreach ($priorityList as $supplierProductId) {
            $candidate = $candidates->first(
                fn (SupplierPriceCandidate $candidate): bool => $candidate->supplierProduct->getKey() === $supplierProductId,
            );

            if ($candidate !== null) {
                $ordered[] = $candidate;
            }
        }

        if ($ordered !== []) {
            return $ordered;
        }

        // Every pinned id went inactive: fall back to the cheapest supplier.
        $cheapest = $candidates->first();

        return $cheapest !== null ? [$cheapest] : [];
    }

    /**
     * Returns the pinned supermarket candidate when the override id matches a
     * recent observation, otherwise the best paying supermarket price.
     */
    private function resolveSupermarketCandidate(CanonicalProduct $canonicalProduct, ?int $supermarketPriceId): ?SupermarketPriceCandidate
    {
        $candidates = $this->supermarketBestPriceQuery->candidatesFor($canonicalProduct);

        if ($supermarketPriceId !== null) {
            $pinned = $candidates->first(
                fn (SupermarketPriceCandidate $candidate): bool => $candidate->price->getKey() === $supermarketPriceId,
            );

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $candidates->first();
    }

    private function resolveSalePrice(
        SupplierPriceCandidate $supplierCandidate,
        ?SupermarketPriceCandidate $supermarketCandidate,
        string $saleMode,
        float $marginInput,
    ): float {
        $landedCost = $supplierCandidate->landedCost;

        return match ($saleMode) {
            self::SALE_FROM_PERCENTAGE => round($landedCost * (1 + ($marginInput / 100)), 4),
            self::SALE_FROM_FIXED => round($landedCost + $marginInput, 4),
            default => $supermarketCandidate?->grossPrice ?? $landedCost,
        };
    }

    private function resolveTenantProduct(CanonicalProduct $canonicalProduct, ?int $tenantId): Product
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $canonicalProduct->name)
            ->first()
            ?? Product::create([
                'tenant_id' => $tenantId,
                'name' => $canonicalProduct->name,
                'status' => 'active',
                'created_by' => auth()->id(),
            ]);
    }
}
