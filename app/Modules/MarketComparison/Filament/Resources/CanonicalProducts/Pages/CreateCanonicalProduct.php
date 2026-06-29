<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\CanonicalProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCanonicalProduct extends CreateRecord
{
    protected static string $resource = CanonicalProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
