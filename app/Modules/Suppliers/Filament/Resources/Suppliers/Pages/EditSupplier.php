<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\Pages;

use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Suppliers\Support\SupplierPhoneNumber;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $phone = SupplierPhoneNumber::split($data['phone'] ?? null, $data['country'] ?? null);
        $data['phone_country'] = $phone['country'];
        $data['phone'] = $phone['number'];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
