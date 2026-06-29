<?php

namespace App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages;

use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierOrder extends EditRecord
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
