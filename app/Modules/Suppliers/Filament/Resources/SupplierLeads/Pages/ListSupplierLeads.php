<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages;

use App\Modules\Suppliers\Filament\Resources\SupplierLeads\SupplierLeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierLeads extends ListRecords
{
    protected static string $resource = SupplierLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
