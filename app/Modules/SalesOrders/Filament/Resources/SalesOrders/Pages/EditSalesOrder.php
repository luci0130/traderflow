<?php

namespace App\Modules\SalesOrders\Filament\Resources\SalesOrders\Pages;

use App\Modules\SalesOrders\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
