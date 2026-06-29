<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupermarketProduct extends EditRecord
{
    protected static string $resource = SupermarketProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
