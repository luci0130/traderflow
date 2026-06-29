<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Modules\Products\Models\Product;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a draft customer (supermarket) offer from market comparison rows. The
 * buy side comes from each canonical product's cheapest supplier (landed cost),
 * the sell side either follows the best supermarket price or applies a margin
 * on top of the landed cost.
 *
 * Alongside the customer offer, one draft supplier offer is created per distinct
 * supplier among the selected products (a customer offer sourced from three
 * suppliers yields three supplier offers), and every customer offer item is
 * linked back to its originating supplier offer item.
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
     * @param  array<int, int>  $supplierOverrides  canonicalProductId => supplierProductId
     * @param  array<int, int>  $supermarketOverrides  canonicalProductId => supermarketPriceId
     * @param  array<int, float|int|string|null>  $quantities  canonicalProductId => offered quantity (defaults to the supplier's available quantity)
     * @param  array<int, float|int|string|null>  $margins  canonicalProductId => per-product margin value (defaults to the offer-level margin)
     */
    public function build(
        Collection $canonicalProducts,
        array $data,
        ?int $tenantId,
        array $supplierOverrides = [],
        array $supermarketOverrides = [],
        array $quantities = [],
        array $margins = [],
    ): CustomerOffer {
        return DB::transaction(function () use ($canonicalProducts, $data, $tenantId, $supplierOverrides, $supermarketOverrides, $quantities, $margins): CustomerOffer {
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

            /** @var array<int, SupplierOffer> $supplierOffers draft supplier offers keyed by supplier id */
            $supplierOffers = [];

            foreach ($canonicalProducts as $canonicalProduct) {
                $supplierCandidate = $this->resolveSupplierCandidate(
                    $canonicalProduct,
                    $supplierOverrides[$canonicalProduct->getKey()] ?? null,
                );

                if ($supplierCandidate === null) {
                    continue;
                }

                $supermarketCandidate = $this->resolveSupermarketCandidate(
                    $canonicalProduct,
                    $supermarketOverrides[$canonicalProduct->getKey()] ?? null,
                );

                $quantity = $this->resolveQuantity($supplierCandidate, $quantities[$canonicalProduct->getKey()] ?? null);
                $product = $this->resolveTenantProduct($canonicalProduct, $tenantId);

                $supplierOfferItem = $this->createSupplierOfferItem(
                    $supplierOffers,
                    $customerOffer,
                    $supplierCandidate,
                    $product,
                    $quantity,
                    $tenantId,
                    $data,
                );

                $this->createItem(
                    $customerOffer,
                    $supplierCandidate,
                    $supermarketCandidate,
                    $product,
                    $supplierOfferItem,
                    $saleMode,
                    $this->resolveMargin($margins[$canonicalProduct->getKey()] ?? null, $marginInput),
                    $tenantId,
                    $quantity,
                );
            }

            return $customerOffer->refresh();
        });
    }

    private function createItem(
        CustomerOffer $customerOffer,
        SupplierPriceCandidate $supplierCandidate,
        ?SupermarketPriceCandidate $supermarketCandidate,
        Product $product,
        SupplierOfferItem $supplierOfferItem,
        string $saleMode,
        float $marginInput,
        ?int $tenantId,
        ?float $quantity,
    ): void {
        $purchasePrice = $supplierCandidate->landedCost;
        $salePrice = $this->resolveSalePrice($supplierCandidate, $supermarketCandidate, $saleMode, $marginInput);

        CustomerOfferItem::create([
            'tenant_id' => $tenantId,
            'customer_offer_id' => $customerOffer->getKey(),
            'product_id' => $product->getKey(),
            'supplier_id' => $supplierCandidate->supplierProduct->producer_id,
            'supplier_product_id' => $supplierCandidate->supplierProduct->getKey(),
            'supplier_offer_item_id' => $supplierOfferItem->getKey(),
            'unit_id' => null,
            'quantity' => $quantity,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'tax_rate' => 0,
        ]);
    }

    /**
     * Creates the supplier offer item for a selected product, reusing (or
     * lazily creating) a single draft supplier offer per supplier so that all
     * products sourced from the same supplier land on one supplier offer.
     *
     * @param  array<int, SupplierOffer>  $supplierOffers  keyed by supplier id, mutated in place
     * @param  array<string, mixed>  $data
     */
    private function createSupplierOfferItem(
        array &$supplierOffers,
        CustomerOffer $customerOffer,
        SupplierPriceCandidate $supplierCandidate,
        Product $product,
        ?float $quantity,
        ?int $tenantId,
        array $data,
    ): SupplierOfferItem {
        $supplierId = (int) $supplierCandidate->supplierProduct->producer_id;

        $supplierOffer = $supplierOffers[$supplierId] ??= SupplierOffer::create([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'customer_offer_id' => $customerOffer->getKey(),
            'received_at' => today(),
            'valid_until' => $data['valid_until'] ?? null,
            'currency' => $supplierCandidate->currency,
            'status' => 'received',
            'source_type' => 'manual',
            'created_by' => auth()->id(),
        ]);

        return SupplierOfferItem::create([
            'tenant_id' => $tenantId,
            'supplier_offer_id' => $supplierOffer->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => null,
            'quantity' => $quantity,
            'purchase_price' => $supplierCandidate->unitPrice,
            'currency' => $supplierCandidate->currency,
        ]);
    }

    /**
     * The offered quantity for an item: the user-supplied value (clamped to the
     * supplier's available quantity) when given, otherwise the full available
     * quantity. A blank override falls back to the available quantity.
     */
    private function resolveQuantity(SupplierPriceCandidate $supplierCandidate, float|int|string|null $override): ?float
    {
        $available = $supplierCandidate->quantityAvailable;

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
     * Returns the pinned supplier candidate when the override id matches an
     * active candidate, otherwise the cheapest supplier.
     */
    private function resolveSupplierCandidate(CanonicalProduct $canonicalProduct, ?int $supplierProductId): ?SupplierPriceCandidate
    {
        $candidates = $this->supplierBestPriceQuery->candidatesFor($canonicalProduct);

        if ($supplierProductId !== null) {
            $pinned = $candidates->first(
                fn (SupplierPriceCandidate $candidate): bool => $candidate->supplierProduct->getKey() === $supplierProductId,
            );

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $candidates->first();
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
