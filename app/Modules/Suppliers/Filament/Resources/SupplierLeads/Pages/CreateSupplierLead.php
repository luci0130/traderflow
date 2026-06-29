<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages;

use App\Modules\Suppliers\Filament\Resources\SupplierLeads\SupplierLeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierLead extends CreateRecord
{
    protected static string $resource = SupplierLeadResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
