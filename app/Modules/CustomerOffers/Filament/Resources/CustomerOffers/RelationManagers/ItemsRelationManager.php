<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\CustomerOffers\Services\CustomerOfferCalculator;
use App\Modules\CustomerOffers\Services\RecalculateOfferItemSourcing;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Collection;

/**
 * The offer's "Items" tab, rendered as the sourcing board: each product line
 * with its prioritized suppliers, grouped either by product or by supplier.
 * The sales agent edits sale price / margin and picks which product→supplier
 * rows go into the order; the purchasing agent fills landed cost and secured
 * quantity. All edits save inline. Being a relation manager, the owner offer is
 * reliably available on every Livewire request.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    protected string $view = 'filament.customer-offers.relation-managers.items-board';

    // Render immediately (not lazily) so the inline inputs hydrate and fire
    // their save requests on change.
    protected static bool $isLazy = false;

    public function editsSell(): bool
    {
        return CustomerOfferResource::showsSellSide();
    }

    public function editsSourcing(): bool
    {
        $user = auth()->user();

        return ($user?->isPurchasingAgent() ?? false) || ($user?->isSuperAdmin() ?? false);
    }

    /**
     * Persist a buy-side cell (landed cost / secured qty) and roll it up onto its
     * offer line's purchase price.
     */
    public function saveSourcing(int $rowId, string $field, mixed $value): void
    {
        if (! $this->editsSourcing() || ! in_array($field, ['landed_cost', 'secured_quantity'], true)) {
            return;
        }

        $row = $this->resolveSupplierRow($rowId);

        if ($row === null) {
            return;
        }

        $row->update([$field => ($value === '' || $value === null) ? null : $value]);

        app(RecalculateOfferItemSourcing::class)->sync($row->item->load('suppliers'));
    }

    /**
     * Persist a sell-side cell. Editing the margin percent recomputes the sale
     * price from the (landed) purchase price, keeping the calculator the single
     * source of truth for the derived margin (and vice versa).
     */
    public function saveSale(int $itemId, string $field, mixed $value): void
    {
        if (! $this->editsSell() || ! in_array($field, ['sale_price', 'margin_percent'], true)) {
            return;
        }

        $item = $this->getOwnerRecord()->items()->whereKey($itemId)->first();

        if ($item === null) {
            return;
        }

        if ($field === 'margin_percent') {
            $purchase = (float) ($item->purchase_price ?? 0);
            $salePrice = round($purchase * (1 + ((float) $value / 100)), 4);
        } else {
            $salePrice = ($value === '' || $value === null) ? null : $value;
        }

        $item->update(['sale_price' => $salePrice]);
    }

    /**
     * Toggle whether a product→supplier row is kept in the order (seller only),
     * then recompute the offer totals.
     */
    public function saveInclude(int $rowId, bool $value): void
    {
        if (! $this->editsSell()) {
            return;
        }

        $row = $this->resolveSupplierRow($rowId);

        if ($row === null) {
            return;
        }

        $row->update(['include_in_order' => $value]);

        // Including/excluding a supplier changes the line's average landed cost,
        // so re-roll the purchase price, then the offer totals.
        app(RecalculateOfferItemSourcing::class)->sync($row->item->load('suppliers'));
        app(CustomerOfferCalculator::class)->recalculateOffer($this->getOwnerRecord());
    }

    /**
     * The offer lines with their prioritized suppliers (Products view).
     */
    public function boardItems(): Collection
    {
        return $this->getOwnerRecord()->items()
            ->with(['product.category', 'supplierProduct', 'unit', 'suppliers.supplier', 'suppliers.supplierProduct'])
            ->get();
    }

    /**
     * The same supplier rows grouped by supplier (Suppliers view).
     *
     * @return Collection<int, array{supplier: mixed, rows: Collection}>
     */
    public function supplierGroups(): Collection
    {
        return $this->supplierRows()
            ->groupBy('supplier_id')
            ->map(fn (Collection $rows): array => [
                'supplier' => $rows->first()->supplier,
                'rows' => $rows,
            ])
            ->values();
    }

    /**
     * Every supplier row on the offer, keeping its parent line so the Suppliers
     * view can show the product.
     *
     * @return Collection<int, CustomerOfferItemSupplier>
     */
    protected function supplierRows(): Collection
    {
        return $this->boardItems()->flatMap(fn ($item) => $item->suppliers->map(function ($supplier) use ($item) {
            $supplier->setRelation('item', $item);

            return $supplier;
        }));
    }

    /**
     * Load a supplier row and confirm it belongs to this offer.
     */
    protected function resolveSupplierRow(int $id): ?CustomerOfferItemSupplier
    {
        $row = CustomerOfferItemSupplier::query()->with('item')->find($id);

        if ($row === null || $row->item?->customer_offer_id !== $this->getOwnerRecord()->getKey()) {
            return null;
        }

        return $row;
    }
}
