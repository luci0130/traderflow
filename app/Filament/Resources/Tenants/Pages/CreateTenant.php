<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\TenantSettings\Services\TenantBankAccounts;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $pendingBankAccounts = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingBankAccounts = $data['bank_accounts'] ?? [];
        unset($data['bank_accounts']);

        return $data;
    }

    protected function afterCreate(): void
    {
        TenantBankAccounts::set($this->getRecord(), $this->pendingBankAccounts);
    }
}
