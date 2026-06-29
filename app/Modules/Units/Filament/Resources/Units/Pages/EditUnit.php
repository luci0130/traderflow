<?php

namespace App\Modules\Units\Filament\Resources\Units\Pages;

use App\Modules\Units\Filament\Resources\Units\UnitResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUnit extends EditRecord
{
    protected static string $resource = UnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
