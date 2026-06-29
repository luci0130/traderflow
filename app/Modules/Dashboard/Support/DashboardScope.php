<?php

namespace App\Modules\Dashboard\Support;

use App\Support\Tenancy\ActiveTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DashboardScope
{
    public const SESSION_KEY = 'dashboard.global_mode';

    public function shouldShowGlobal(): bool
    {
        return auth()->user()?->isSuperAdmin() === true
            && app(ActiveTenant::class)->id() === null;
    }

    public function tenantId(): ?int
    {
        if ($this->shouldShowGlobal()) {
            return null;
        }

        return app(ActiveTenant::class)->id();
    }

    /**
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyTo(Builder|QueryBuilder $query, string $column = 'tenant_id'): Builder|QueryBuilder
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return $query;
        }

        return $query->where($column, $tenantId);
    }

    public function toggleGlobalMode(): void
    {
        if (auth()->user()?->isSuperAdmin() !== true) {
            return;
        }

        if ($this->shouldShowGlobal()) {
            $tenant = auth()->user()?->getTenants(filament()->getDefaultPanel())->first();

            if ($tenant !== null) {
                app(ActiveTenant::class)->set($tenant);
            }

            return;
        }

        app(ActiveTenant::class)->set(null);
    }
}
