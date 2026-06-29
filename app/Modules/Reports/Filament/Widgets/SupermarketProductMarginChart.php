<?php

namespace App\Modules\Reports\Filament\Widgets;

use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Primary entity: the supermarket. After picking one, the chart shows which products
 * it accepted (green) or rejected (red) and at what margin, over time. Each point is
 * labelled with the product.
 */
class SupermarketProductMarginChart extends MarginScatterChart
{
    public function getHeading(): ?string
    {
        $supermarket = $this->selectedSupermarket();

        if ($supermarket === null) {
            return __('Accepted / rejected product margins');
        }

        return __('Accepted / rejected product margins').' — '.$supermarket->name;
    }

    public function getDescription(): ?string
    {
        if ($this->selectedSupermarket() === null) {
            return __('Pick a supermarket to see the products it accepted or rejected.');
        }

        return __('Points are coloured by offer status (see legend); the line joins accepted offers. Each point is labelled with the product. Scroll to zoom the time axis, drag to pan, double-click to reset.');
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->supermarketFilter()
                    ->default(fn (): ?int => request()->integer('supermarket') ?: null),
                $this->productFilter()
                    ->placeholder(__('All')),
                $this->marginTypeFilter(),
                $this->periodFilter(),
            ]);
    }

    protected function getData(): array
    {
        $supermarketId = $this->filters['supermarket_id'] ?? null;

        if (! $supermarketId) {
            return ['datasets' => []];
        }

        $productId = $this->filters['product_id'] ?? null;
        $usesPercent = $this->usesPercent();
        $periodStart = $this->periodStart($this->filters['period'] ?? 'all');
        $productNames = $this->productNames();

        $items = CustomerOfferItem::query()
            ->when($productId, fn (Builder $query): Builder => $query->where('product_id', $productId))
            ->whereHas('customerOffer', function (Builder $query) use ($supermarketId, $periodStart): void {
                $query->whereIn('status', $this->chartStatuses())
                    ->where('customer_id', $supermarketId)
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
                'label' => $productNames[$item->product_id] ?? '—',
                'status' => $offer->status,
                'currency' => $offer->currency,
            ];
        }

        return $this->buildDatasets($rows, $usesPercent);
    }
}
