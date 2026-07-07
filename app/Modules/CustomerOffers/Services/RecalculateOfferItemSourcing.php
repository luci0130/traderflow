<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOfferItem;

/**
 * Rolls the buy-side sourcing entered by the purchasing agent up onto the offer
 * line: the purchase price becomes the "Landed Cost Mediu" — the secured-quantity
 * -weighted average landed cost across the line's suppliers. The desired quantity
 * and the sale price are left untouched (they belong to the sales side); the
 * secured total is shown separately, not stored on the line.
 */
class RecalculateOfferItemSourcing
{
    public function sync(CustomerOfferItem $item): void
    {
        // Only suppliers kept in the order contribute to the line's purchase price.
        $secured = $item->suppliers
            ->filter(fn ($supplier): bool => $supplier->include_in_order
                && (float) ($supplier->secured_quantity ?? 0) > 0
                && $supplier->landed_cost !== null);

        $weight = (float) $secured->sum(fn ($supplier): float => (float) $supplier->secured_quantity);

        if ($weight <= 0) {
            return;
        }

        $weightedLanded = $secured->sum(
            fn ($supplier): float => (float) $supplier->secured_quantity * (float) $supplier->landed_cost,
        ) / $weight;

        // Triggers CustomerOfferItemObserver, which recomputes margin/line/offer totals.
        $item->update(['purchase_price' => round($weightedLanded, 4)]);
    }
}
