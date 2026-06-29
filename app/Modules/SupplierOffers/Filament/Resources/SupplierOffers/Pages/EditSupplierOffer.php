<?php

namespace App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages;

use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierOffer extends EditRecord
{
    protected static string $resource = SupplierOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
