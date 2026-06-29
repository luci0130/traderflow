<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages;

use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierProduct extends EditRecord
{
    protected static string $resource = SupplierProductResource::class;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $priceBreaks = [];

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $costOverride = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['prices'] = $record->prices
            ->map(fn ($price): array => [
                'min_quantity_value' => $price->min_quantity_value,
                'unit_price' => $price->unit_price,
            ])
            ->all();

        $data['cost_override'] = $record->costOverride
            ?->only(['packaging_cost', 'transport_cost', 'commission', 'profit_margin']) ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->priceBreaks = $data['prices'] ?? [];
        $this->costOverride = $data['cost_override'] ?? null;

        unset($data['prices'], $data['cost_override']);

        return $data;
    }

    protected function afterSave(): void
    {
        SupplierProductResource::persistPriceBreaks($this->record, $this->priceBreaks);
        SupplierProductResource::persistCostOverride($this->record, $this->costOverride);
    }
}
