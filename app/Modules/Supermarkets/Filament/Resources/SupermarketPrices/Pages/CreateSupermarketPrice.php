<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\SupermarketPriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupermarketPrice extends CreateRecord
{
    protected static string $resource = SupermarketPriceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['recorded_by'] = auth()->id();

        return $data;
    }
}
