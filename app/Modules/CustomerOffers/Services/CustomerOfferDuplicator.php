<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerOfferDuplicator
{
    public function duplicate(CustomerOffer $offer): CustomerOffer
    {
        Gate::authorize('view', $offer);
        Gate::authorize('create', CustomerOffer::class);

        return DB::transaction(function () use ($offer): CustomerOffer {
            $offer = CustomerOffer::query()->with('items.suppliers')->findOrFail($offer->getKey());
            $duplicate = $offer->replicate(['offer_number', 'sent_at']);
            $duplicate->setRelations([]);
            $duplicate->forceFill([
                'status' => 'draft',
                'offer_date' => today(),
                'created_by' => auth()->id(),
            ])->save();

            foreach ($offer->items as $item) {
                $newItem = $item->replicate();
                $newItem->setRelations([]);
                $newItem->customer_offer_id = $duplicate->getKey();
                $newItem->save();

                foreach ($item->suppliers as $supplier) {
                    $newSupplier = $supplier->replicate();
                    $newSupplier->setRelations([]);
                    $newSupplier->customer_offer_item_id = $newItem->getKey();
                    $newSupplier->save();
                }
            }

            app(CustomerOfferCalculator::class)->recalculateOffer($duplicate);

            return $duplicate->refresh();
        });
    }
}
