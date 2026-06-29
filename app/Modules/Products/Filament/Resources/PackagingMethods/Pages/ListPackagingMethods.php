<?php

namespace App\Modules\Products\Filament\Resources\PackagingMethods\Pages;

use App\Modules\Products\Filament\Resources\PackagingMethods\PackagingMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackagingMethods extends ListRecords
{
    protected static string $resource = PackagingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
