<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupermarketProduct extends CreateRecord
{
    protected static string $resource = SupermarketProductResource::class;
}
