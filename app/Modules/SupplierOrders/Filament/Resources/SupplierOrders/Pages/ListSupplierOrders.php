<?php

namespace App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages;

use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierOrders extends ListRecords
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
