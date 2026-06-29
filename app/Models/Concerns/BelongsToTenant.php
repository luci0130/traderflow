<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\ActiveTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('active_tenant', function (Builder $query): void {
            if (auth()->user()?->isSuperAdmin()) {
                return;
            }

            $tenantId = app(ActiveTenant::class)->id();

            if ($tenantId !== null) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model): void {
            if ($model->tenant_id !== null) {
                return;
            }

            $tenantId = app(ActiveTenant::class)->id();

            if ($tenantId !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForActiveTenant(Builder $query): Builder
    {
        $tenantId = app(ActiveTenant::class)->id();

        if ($tenantId === null) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
    }
}
