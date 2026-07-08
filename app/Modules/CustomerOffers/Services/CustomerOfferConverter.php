<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns an accepted customer offer into the customer (sales) order. Each line
 * carries the sale price and the *total secured quantity* — the amount the
 * purchasing agent actually sourced across the suppliers kept in the order —
 * rather than the desired quantity. Lines with nothing secured are skipped; a
 * line with no sourcing at all falls back to its desired quantity.
 */
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
            $offer->loadMissing('items.suppliers');

            $lines = $offer->items
                ->filter(fn (CustomerOfferItem $item): bool => $item->isIncludedInOrder())
                ->map(fn (CustomerOfferItem $item): array => $this->lineFor($item))
                ->filter(fn (array $line): bool => $line['quantity'] > 0)
                ->values();

            $subtotal = $lines->sum(fn (array $line): float => $line['line_total']);
            $taxTotal = $lines->sum(fn (array $line): float => $line['line_total'] * $line['tax_rate'] / 100);

            $salesOrder = SalesOrder::create([
                'tenant_id' => $offer->tenant_id,
                'customer_offer_id' => $offer->getKey(),
                'customer_id' => $offer->customer_id,
                'order_date' => today(),
                'currency' => $offer->currency,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $subtotal + $taxTotal,
                'notes' => $offer->notes,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $line) {
                SalesOrderItem::create([
                    'tenant_id' => $offer->tenant_id,
                    'sales_order_id' => $salesOrder->getKey(),
                    'product_id' => $line['item']->product_id,
                    'supplier_id' => $line['item']->supplier_id,
                    'supplier_product_id' => $line['item']->supplier_product_id,
                    'unit_id' => $line['item']->unit_id,
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['item']->purchase_price,
                    'sale_price' => $line['item']->sale_price,
                    'margin_value' => $line['item']->margin_value,
                    'margin_percent' => $line['item']->margin_percent,
                    'line_total' => $line['line_total'],
                    'notes' => $line['item']->notes,
                ]);
            }

            return $salesOrder->refresh();
        });
    }

    /**
     * The sales-order line for an offer item: the total secured quantity when the
     * line has been sourced, falling back to the desired quantity for a kept line
     * that has nothing secured yet.
     *
     * @return array{item: CustomerOfferItem, quantity: float, tax_rate: float, line_total: float}
     */
    private function lineFor(CustomerOfferItem $item): array
    {
        $secured = $item->totalSecuredQuantity();
        $quantity = $secured > 0 ? $secured : (float) ($item->quantity ?? 0);

        $lineTotal = $quantity * (float) ($item->sale_price ?? 0);

        return [
            'item' => $item,
            'quantity' => $quantity,
            'tax_rate' => (float) ($item->tax_rate ?? 0),
            'line_total' => $lineTotal,
        ];
    }
}
