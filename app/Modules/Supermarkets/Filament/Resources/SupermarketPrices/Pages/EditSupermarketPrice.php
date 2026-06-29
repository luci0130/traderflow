<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\SupermarketPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupermarketPrice extends EditRecord
{
    protected static string $resource = SupermarketPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
