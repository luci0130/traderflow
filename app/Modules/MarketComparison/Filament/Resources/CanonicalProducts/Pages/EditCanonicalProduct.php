<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\CanonicalProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCanonicalProduct extends EditRecord
{
    protected static string $resource = CanonicalProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
