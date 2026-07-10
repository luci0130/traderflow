<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\TenantSettings\Services\TenantBankAccounts;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Bank accounts held aside between save (stripped from the model data) and
     * afterSave (persisted to the tenant setting).
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $pendingBankAccounts = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bank_accounts'] = TenantBankAccounts::get($this->getRecord());

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingBankAccounts = $data['bank_accounts'] ?? [];
        unset($data['bank_accounts']);

        return $data;
    }

    protected function afterSave(): void
    {
        TenantBankAccounts::set($this->getRecord(), $this->pendingBankAccounts);
    }
}
