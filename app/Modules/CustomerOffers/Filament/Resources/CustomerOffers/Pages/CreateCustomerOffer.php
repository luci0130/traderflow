<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerOffer extends CreateRecord
{
    protected static string $resource = CustomerOfferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
