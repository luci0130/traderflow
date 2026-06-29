<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupermarketProducts extends ListRecords
{
    protected static string $resource = SupermarketProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
