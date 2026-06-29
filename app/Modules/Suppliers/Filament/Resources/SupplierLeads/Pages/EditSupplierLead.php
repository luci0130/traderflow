<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages;

use App\Modules\Suppliers\Filament\Resources\SupplierLeads\SupplierLeadResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierLead extends EditRecord
{
    protected static string $resource = SupplierLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
