<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Support\ExchangeRate;

class RecalculateOfferItemSourcing
{
    public function __construct(private ExchangeRate $exchangeRate) {}

    public function sync(CustomerOfferItem $item): void
    {
        $average = $this->averageUnitCost($item);

        if ($average !== null) {
            $item->update(['purchase_price' => $average]);
        }
    }

    public function averageUnitCost(CustomerOfferItem $item, ?string $currency = null): ?float
    {
        $secured = $item->suppliers->filter(fn (CustomerOfferItemSupplier $supplier): bool => $supplier->include_in_order
            && (float) ($supplier->secured_quantity ?? 0) > 0
            && $supplier->total_cost !== null);
        $quantity = (float) $secured->sum('secured_quantity');

        if ($quantity <= 0) {
            return null;
        }

        $currency ??= $item->customerOffer->currency;
        $total = 0.0;

        foreach ($secured as $supplier) {
            $cost = $this->exchangeRate->convert($supplier->total_cost, $supplier->currency, $currency);

            if ($cost === null) {
                return null;
            }

            $total += $cost;
        }

        return round($total / $quantity, 4);
    }
}
