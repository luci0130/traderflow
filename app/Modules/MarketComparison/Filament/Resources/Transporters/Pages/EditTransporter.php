<?php

namespace App\Modules\MarketComparison\Filament\Resources\Transporters\Pages;

use App\Modules\MarketComparison\Filament\Resources\Transporters\TransporterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransporter extends EditRecord
{
    protected static string $resource = TransporterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
