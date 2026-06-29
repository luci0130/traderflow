<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\SupermarketPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupermarketPrices extends ListRecords
{
    protected static string $resource = SupermarketPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
