<?php

namespace App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages;

use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierOrder extends CreateRecord
{
    protected static string $resource = SupplierOrderResource::class;
}
