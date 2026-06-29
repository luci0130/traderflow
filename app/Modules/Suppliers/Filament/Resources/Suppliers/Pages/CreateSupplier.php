<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\Pages;

use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;
}
