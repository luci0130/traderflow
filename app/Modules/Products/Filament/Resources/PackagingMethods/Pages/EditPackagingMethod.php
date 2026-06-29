<?php

namespace App\Modules\Products\Filament\Resources\PackagingMethods\Pages;

use App\Modules\Products\Filament\Resources\PackagingMethods\PackagingMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPackagingMethod extends EditRecord
{
    protected static string $resource = PackagingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
