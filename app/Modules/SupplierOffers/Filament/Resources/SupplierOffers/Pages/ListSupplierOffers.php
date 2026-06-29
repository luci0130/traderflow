<?php

namespace App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages;

use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierOffers extends ListRecords
{
    protected static string $resource = SupplierOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
