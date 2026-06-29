<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages;

use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierProduct extends CreateRecord
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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->priceBreaks = $data['prices'] ?? [];
        $this->costOverride = $data['cost_override'] ?? null;

        unset($data['prices'], $data['cost_override']);

        return $data;
    }

    protected function afterCreate(): void
    {
        SupplierProductResource::persistPriceBreaks($this->record, $this->priceBreaks);
        SupplierProductResource::persistCostOverride($this->record, $this->costOverride);
    }
}
