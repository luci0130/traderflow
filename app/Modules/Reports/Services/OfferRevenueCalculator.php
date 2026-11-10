<?php

namespace App\Modules\Reports\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\Reports\Data\OfferRevenue;
use App\Support\ExchangeRate;

class OfferRevenueCalculator
{
    public const REALIZED_ORDER_STATUSES = ['delivered', 'invoiced', 'paid'];

    public function __construct(private ExchangeRate $exchangeRate) {}

    public function calculate(CustomerOffer $offer): OfferRevenue
    {
        $offer->loadMissing(['items.suppliers', 'salesOrder.items']);
        $sales = 0.0;
        $products = 0.0;
        $transport = 0.0;
        $salesKnown = true;
        $costsKnown = true;
        $transportKnown = true;
        $complete = $offer->items->isNotEmpty();
        $estimated = false;
        $bundled = false;
        $hasOrder = $offer->salesOrder !== null;

        foreach ($offer->items as $item) {
            if (! $item->isIncludedInOrder()) {
                continue;
            }

            $sources = $item->suppliers->filter(fn (CustomerOfferItemSupplier $source): bool => $source->include_in_order);

            if ($sources->isEmpty()) {
                $quantity = $hasOrder
                    ? (float) $offer->salesOrder->items->where('product_id', $item->product_id)->sum('quantity')
                    : (float) ($item->quantity ?? 0);
                $sales += $quantity * (float) ($item->sale_price ?? 0);
                $salesKnown = $salesKnown && $item->sale_price !== null;
                $hasSupplier = $item->supplier_id !== null || $item->supplier_product_id !== null;
                $known = $hasSupplier && $item->purchase_price !== null;
                $costsKnown = $costsKnown && $known;
                $products += $quantity * (float) ($item->purchase_price ?? 0);
                $bundled = $bundled || $known;
                $complete = $complete && $known && $quantity > 0;
                $estimated = $estimated || ! $hasOrder;

                continue;
            }

            $remaining = max(0.0, (float) ($item->quantity ?? 0) - (float) $sources->sum('secured_quantity'));
            $canEstimate = ! $hasOrder && $offer->status !== 'accepted';
            $requiresEstimate = $canEstimate && $sources->contains(fn (CustomerOfferItemSupplier $source): bool => $source->secured_quantity === null);
            $quantityTotal = 0.0;

            foreach ($sources as $source) {
                $quantity = $source->secured_quantity !== null ? (float) $source->secured_quantity : 0.0;

                if ($source->secured_quantity === null && $canEstimate && $remaining > 0) {
                    $quantity = $source->quantity_available !== null
                        ? min($remaining, max(0.0, (float) $source->quantity_available)) : $remaining;
                    $remaining -= $quantity;
                    $estimated = true;
                }

                if ($quantity <= 0) {
                    continue;
                }

                $quantityTotal += $quantity;
                $sales += (float) ($item->sale_price ?? 0) * $quantity;
                $salesKnown = $salesKnown && $item->sale_price !== null;
                [$productCost, $transportCost, $includesTransport] = $this->sourceCosts($source, $quantity, $offer->currency);
                $costsKnown = $costsKnown && $productCost !== null;
                $transportKnown = $transportKnown && $transportCost !== null;
                $products += $productCost ?? 0;
                $transport += $transportCost ?? 0;
                $bundled = $bundled || $includesTransport;
            }

            $complete = $complete && $quantityTotal > 0 && (! $requiresEstimate || $remaining <= 0);
        }

        if ($hasOrder) {
            $sales = 0.0;
            $salesKnown = true;

            foreach ($offer->salesOrder->items as $line) {
                $value = $line->sale_price !== null && $line->quantity !== null
                    ? $this->exchangeRate->convert((float) $line->sale_price * (float) $line->quantity, $offer->salesOrder->currency, $offer->currency) : null;
                $salesKnown = $salesKnown && $value !== null;
                $sales += $value ?? 0;
            }

            $complete = $complete && $offer->salesOrder->items->isNotEmpty();
        }

        return new OfferRevenue(
            salesTotal: $salesKnown ? round($sales, 4) : null,
            productCost: $costsKnown ? round($products, 4) : null,
            transportCost: $transportKnown ? round($transport, 4) : null,
            usesEstimatedQuantities: $estimated,
            hasBundledCosts: $bundled,
            isComplete: $complete && $salesKnown && $costsKnown && $transportKnown,
        );
    }

    /** @return array{?float, ?float, bool} */
    private function sourceCosts(CustomerOfferItemSupplier $source, float $quantity, string $currency): array
    {
        $shipping = (float) $source->transport_cost;
        $price = $source->unit_price ?? $source->landed_cost;
        $bundled = $source->unit_price === null && $source->landed_cost !== null;
        $products = $price !== null ? (float) $price * $quantity : null;

        if ($products !== null && $bundled) {
            $products -= $shipping;
        }

        return [
            $products !== null && $products >= 0 ? $this->exchangeRate->convert($products, $source->currency, $currency) : null,
            $this->exchangeRate->convert($shipping, $source->currency, $currency),
            $bundled,
        ];
    }
}
