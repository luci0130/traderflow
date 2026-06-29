<?php

namespace App\Modules\CustomerOffers\Observers;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferCalculator;

class CustomerOfferItemObserver
{
    public function __construct(private readonly CustomerOfferCalculator $calculator) {}

    public function saving(CustomerOfferItem $item): void
    {
        $this->calculator->applyToItem($item);
    }

    public function saved(CustomerOfferItem $item): void
    {
        $this->recalculateParent($item);
    }

    public function deleted(CustomerOfferItem $item): void
    {
        $this->recalculateParent($item);
    }

    private function recalculateParent(CustomerOfferItem $item): void
    {
        $offer = $item->customerOffer()->first();

        if ($offer instanceof CustomerOffer) {
            $this->calculator->recalculateOffer($offer);
        }
    }
}
