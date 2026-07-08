<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\SupplierOrders\Models\SupplierOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a customer offer: marks it accepted, turns it into the customer (sales)
 * order and, from the per-line sourcing, creates one supplier order per supplier
 * kept in the order — each carrying the quantity that supplier secured.
 * Idempotent — re-running never duplicates orders.
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

            $offer->refresh()->loadMissing('items.suppliers.supplierProduct');

            $salesOrder = $offer->salesOrder()->exists()
                ? $offer->salesOrder
                : $this->converter->convert($offer);

            $this->createSupplierOrders($offer);

            return $salesOrder;
        });
    }

    /**
     * One supplier order per supplier chosen for the order, grouping every secured
     * sourcing line of that supplier. Suppliers that already have an order for this
     * offer are skipped so the whole method stays idempotent.
     */
    private function createSupplierOrders(CustomerOffer $offer): void
    {
        $orderedSupplierIds = SupplierOrder::query()
            ->where('customer_offer_id', $offer->getKey())
            ->pluck('supplier_id')
            ->all();

        $offer->items
            ->flatMap(fn ($item): Collection => $item->suppliers
                ->filter(fn (CustomerOfferItemSupplier $source): bool => (bool) $source->include_in_order
                    && (float) ($source->secured_quantity ?? 0) > 0)
                ->map(fn (CustomerOfferItemSupplier $source): array => ['item' => $item, 'source' => $source]))
            ->groupBy(fn (array $line): int => (int) $line['source']->supplier_id)
            ->reject(fn (Collection $lines, int $supplierId): bool => in_array($supplierId, $orderedSupplierIds, true))
            ->each(fn (Collection $lines, int $supplierId) => $this->createSupplierOrder($offer, $supplierId, $lines));
    }

    /**
     * @param  Collection<int, array{item: CustomerOfferItem, source: CustomerOfferItemSupplier}>  $lines
     */
    private function createSupplierOrder(CustomerOffer $offer, int $supplierId, Collection $lines): SupplierOrder
    {
        $currency = $lines->first()['source']->currency ?: $offer->currency;

        $total = $lines->sum(fn (array $line): float => $this->lineTotal($line['source']));

        $supplierOrder = SupplierOrder::create([
            'tenant_id' => $offer->tenant_id,
            'supplier_id' => $supplierId,
            'customer_offer_id' => $offer->getKey(),
            'order_date' => today(),
            'currency' => $currency,
            'status' => 'draft',
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'created_by' => auth()->id(),
        ]);

        foreach ($lines as $line) {
            $item = $line['item'];
            $source = $line['source'];

            SupplierOrderItem::create([
                'tenant_id' => $offer->tenant_id,
                'supplier_order_id' => $supplierOrder->getKey(),
                'product_id' => $item->product_id,
                'supplier_product_id' => $source->supplier_product_id,
                'unit_id' => $item->unit_id,
                'quantity' => $source->secured_quantity,
                'purchase_price' => $this->purchasePrice($source),
                'currency' => $source->currency ?: $offer->currency,
                'line_total' => $this->lineTotal($source),
                'notes' => $item->notes,
            ]);
        }

        return $supplierOrder;
    }

    private function lineTotal(CustomerOfferItemSupplier $source): float
    {
        return (float) $source->secured_quantity * $this->purchasePrice($source);
    }

    /**
     * The agreed buy price: the landed cost the purchasing agent entered, falling
     * back to the supplier's offered unit price.
     */
    private function purchasePrice(CustomerOfferItemSupplier $source): float
    {
        return (float) ($source->landed_cost ?? $source->unit_price ?? 0);
    }
}
