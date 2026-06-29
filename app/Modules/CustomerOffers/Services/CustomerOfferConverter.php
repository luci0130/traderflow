<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerOfferConverter
{
    public function convert(CustomerOffer $offer): SalesOrder
    {
        if ($offer->status !== 'accepted') {
            throw new RuntimeException('Only accepted customer offers can be converted to a sales order.');
        }

        if ($offer->salesOrder()->exists()) {
            throw new RuntimeException('A sales order already exists for this customer offer.');
        }

        return DB::transaction(function () use ($offer): SalesOrder {
            $offer->loadMissing('items');

            $salesOrder = SalesOrder::create([
                'tenant_id' => $offer->tenant_id,
                'customer_offer_id' => $offer->getKey(),
                'customer_id' => $offer->customer_id,
                'order_date' => today(),
                'currency' => $offer->currency,
                'status' => 'draft',
                'subtotal' => $offer->subtotal,
                'tax_total' => $offer->tax_total,
                'total' => $offer->total,
                'notes' => $offer->notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($offer->items as $item) {
                SalesOrderItem::create([
                    'tenant_id' => $offer->tenant_id,
                    'sales_order_id' => $salesOrder->getKey(),
                    'product_id' => $item->product_id,
                    'supplier_id' => $item->supplier_id,
                    'unit_id' => $item->unit_id,
                    'quantity' => $item->quantity,
                    'purchase_price' => $item->purchase_price,
                    'sale_price' => $item->sale_price,
                    'margin_value' => $item->margin_value,
                    'margin_percent' => $item->margin_percent,
                    'line_total' => $item->line_total,
                    'notes' => $item->notes,
                ]);
            }

            return $salesOrder->refresh();
        });
    }
}
