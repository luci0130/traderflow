<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class ActiveTenant
{
    public function id(): ?int
    {
        return $this->model()?->getKey() ?? session('tenant_id');
    }

    public function model(): ?Tenant
    {
        $tenantId = session('tenant_id');

        if ($tenantId !== null) {
            $tenant = Tenant::query()->find($tenantId);

            if (($tenant instanceof Tenant) && $this->canAccessTenant($tenant)) {
                return $tenant;
            }

            session()->forget('tenant_id');
        }

        return $this->defaultTenant();
    }

    public function set(?Tenant $tenant): void
    {
        if ($tenant === null) {
            session()->forget('tenant_id');

            return;
        }

        session(['tenant_id' => $tenant->getKey()]);
    }

    protected function defaultTenant(): ?Tenant
    {
        $user = auth()->user();

        if ($user === null || $user->isSuperAdmin()) {
            return null;
        }

        $tenant = $user->getTenants(filament()->getDefaultPanel())->first();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    protected function canAccessTenant(Model $tenant): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return true;
        }

        return $user->canAccessTenant($tenant);
    }
}
