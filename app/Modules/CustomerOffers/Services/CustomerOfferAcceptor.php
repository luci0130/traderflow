<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\SupplierOrders\Models\SupplierOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a customer offer: marks it accepted, turns it into a sales order (sell
 * side) and turns every linked supplier offer into its own supplier order (buy
 * side). Idempotent — re-running never duplicates orders.
 */
class CustomerOfferAcceptor
{
    public function __construct(private CustomerOfferConverter $converter) {}

    public function accept(CustomerOffer $offer): SalesOrder
    {
        return DB::transaction(function () use ($offer): SalesOrder {
            if ($offer->status !== 'accepted') {
                $offer->update(['status' => 'accepted']);
            }

            $offer->refresh()->loadMissing('supplierOffers.items');

            $salesOrder = $offer->salesOrder()->exists()
                ? $offer->salesOrder
                : $this->converter->convert($offer);

            foreach ($offer->supplierOffers as $supplierOffer) {
                if ($supplierOffer->supplierOrder()->exists()) {
                    continue;
                }

                $this->createSupplierOrder($offer, $supplierOffer);
            }

            return $salesOrder;
        });
    }

    private function createSupplierOrder(CustomerOffer $offer, SupplierOffer $supplierOffer): SupplierOrder
    {
        $total = $supplierOffer->items->sum(
            fn ($item): float => (float) ($item->quantity ?? 0) * (float) $item->purchase_price,
        );

        $supplierOrder = SupplierOrder::create([
            'tenant_id' => $supplierOffer->tenant_id,
            'supplier_id' => $supplierOffer->supplier_id,
            'supplier_offer_id' => $supplierOffer->getKey(),
            'customer_offer_id' => $offer->getKey(),
            'order_date' => today(),
            'currency' => $supplierOffer->currency,
            'status' => 'draft',
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'created_by' => auth()->id(),
        ]);

        foreach ($supplierOffer->items as $item) {
            $lineTotal = (float) ($item->quantity ?? 0) * (float) $item->purchase_price;

            SupplierOrderItem::create([
                'tenant_id' => $supplierOffer->tenant_id,
                'supplier_order_id' => $supplierOrder->getKey(),
                'product_id' => $item->product_id,
                'unit_id' => $item->unit_id,
                'quantity' => $item->quantity,
                'purchase_price' => $item->purchase_price,
                'currency' => $item->currency,
                'line_total' => $lineTotal,
                'notes' => $item->notes,
            ]);
        }

        // Mark the supplier offer as approved now that it has become an order.
        $supplierOffer->update(['status' => 'approved']);

        return $supplierOrder;
    }
}
