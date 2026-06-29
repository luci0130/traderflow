<?php

namespace App\Modules\Reports\Filament\Widgets;

use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Primary entity: the product. After picking one, the chart shows which supermarkets
 * accepted (green) or rejected (red) it and at what margin, over time. Each point is
 * labelled with the supermarket.
 */
class SupermarketMarginChart extends MarginScatterChart
{
    public function getHeading(): ?string
    {
        $product = $this->selectedProduct();

        if ($product === null) {
            return __('Accepted / rejected supermarket margins');
        }

        return __('Accepted / rejected supermarket margins').' — '.$product->name;
    }

    public function getDescription(): ?string
    {
        if ($this->selectedProduct() === null) {
            return __('Pick a product to see the margins supermarkets accepted or rejected.');
        }

        return __('Points are coloured by offer status (see legend); the line joins accepted offers. Each point is labelled with the supermarket. Scroll to zoom the time axis, drag to pan, double-click to reset.');
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->productFilter()
                    ->default(fn (): ?int => request()->integer('product') ?: null),
                $this->supermarketFilter()
                    ->placeholder(__('All')),
                $this->marginTypeFilter(),
                $this->periodFilter(),
            ]);
    }

    protected function getData(): array
    {
        $productId = $this->filters['product_id'] ?? null;

        if (! $productId) {
            return ['datasets' => []];
        }

        $supermarketId = $this->filters['supermarket_id'] ?? null;
        $usesPercent = $this->usesPercent();
        $periodStart = $this->periodStart($this->filters['period'] ?? 'all');
        $names = $this->supermarketNames();

        $items = CustomerOfferItem::query()
            ->where('product_id', $productId)
            ->whereHas('customerOffer', function (Builder $query) use ($supermarketId, $names, $periodStart): void {
                $query->whereIn('status', $this->chartStatuses())
                    ->whereIn('customer_id', $names->keys())
                    ->when($supermarketId, fn (Builder $query): Builder => $query->where('customer_id', $supermarketId))
                    ->when($periodStart, fn (Builder $query): Builder => $query->where('updated_at', '>=', $periodStart));
            })
            ->with('customerOffer')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $offer = $item->customerOffer;

            if ($offer === null) {
                continue;
            }

            $rows[] = [
                'x' => $offer->updated_at?->toIso8601String(),
                'y' => (float) ($usesPercent ? $item->margin_percent : $item->margin_value),
                'label' => $names[$offer->customer_id] ?? '—',
                'status' => $offer->status,
                'currency' => $offer->currency,
            ];
        }

        return $this->buildDatasets($rows, $usesPercent);
    }
}
