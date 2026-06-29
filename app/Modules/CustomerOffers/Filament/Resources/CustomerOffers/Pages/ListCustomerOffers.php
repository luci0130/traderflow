<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerOffers extends ListRecords
{
    protected static string $resource = CustomerOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
