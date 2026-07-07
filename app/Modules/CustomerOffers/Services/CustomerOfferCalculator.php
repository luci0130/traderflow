<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;

class CustomerOfferCalculator
{
    /**
     * @return array{margin_value: float, margin_percent: float, line_total: float}
     */
    public function lineItemTotals(?float $quantity, ?float $purchasePrice, ?float $salePrice): array
    {
        $purchasePrice ??= 0.0;
        $salePrice ??= 0.0;

        $marginValue = $salePrice - $purchasePrice;
        $marginPercent = $purchasePrice > 0 ? ($marginValue / $purchasePrice) * 100 : 0.0;
        $lineTotal = $quantity !== null ? $quantity * $salePrice : 0.0;

        return [
            'margin_value' => $marginValue,
            'margin_percent' => $marginPercent,
            'line_total' => $lineTotal,
        ];
    }

    public function applyToItem(CustomerOfferItem $item): void
    {
        $totals = $this->lineItemTotals(
            $item->quantity !== null ? (float) $item->quantity : null,
            $item->purchase_price !== null ? (float) $item->purchase_price : null,
            $item->sale_price !== null ? (float) $item->sale_price : null,
        );

        $item->margin_value = $totals['margin_value'];
        $item->margin_percent = $totals['margin_percent'];
        $item->line_total = $totals['line_total'];
    }

    public function recalculateOffer(CustomerOffer $offer): void
    {
        // Only lines the seller keeps in the order (via their suppliers) contribute
        // to the offer totals.
        $items = $offer->items()->with('suppliers')->get()
            ->filter(fn (CustomerOfferItem $item): bool => $item->isIncludedInOrder());

        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) ($item->line_total ?? 0);
            $taxRate = (float) ($item->tax_rate ?? 0);

            $subtotal += $lineTotal;
            $taxTotal += $lineTotal * $taxRate / 100;
        }

        $offer->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal,
        ])->saveQuietly();
    }
}
