<?php

namespace App\Filament\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\ActiveTenant;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

trait ScopesToActiveTenant
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenantId = static::getActiveTenantId();

        if (static::canSeeAllTenants()) {
            return $query;
        }

        if ($tenantId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Visible, required picker letting the user choose which tenant (company) a
     * document is created for ("de pe ce tenant se trimite"). Defaults to the
     * active tenant when one is selected.
     */
    protected static function tenantSelect(): Select
    {
        return Select::make('tenant_id')
            ->label(__('Tenant'))
            ->options(fn (): array => Tenant::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->default(fn (): ?int => static::getActiveTenantId())
            ->required()
            ->searchable()
            ->native(false);
    }

    protected static function canSeeAllTenants(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false)
            && static::getActiveTenantId() === null;
    }

    protected static function getActiveTenantId(): ?int
    {
        return app(ActiveTenant::class)->id();
    }
}
