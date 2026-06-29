<?php

namespace App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages;

use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierOffer extends CreateRecord
{
    protected static string $resource = SupplierOfferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
