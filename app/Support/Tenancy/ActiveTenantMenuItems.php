<?php

namespace App\Support\Tenancy;

use App\Filament\Pages\ActiveTenant as ActiveTenantPage;
use Filament\Navigation\MenuItem;
use Filament\Support\Icons\Heroicon;

class ActiveTenantMenuItems
{
    /**
     * @return array<string, MenuItem>
     */
    public static function filamentUserMenuItems(): array
    {
        return [
            'active_tenant' => MenuItem::make()
                ->label(fn (): string => __('Active tenant: :tenant', [
                    'tenant' => static::currentLabel(),
                ]))
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->url(fn (): string => ActiveTenantPage::getUrl()),
            'active_tenant_global' => MenuItem::make()
                ->label(fn (): string => app(ActiveTenant::class)->id() === null ? '* '.__('Global') : __('Global'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->url(fn (): string => route('active-tenant.global'))
                ->visible(fn (): bool => auth()->user()?->isSuperAdmin() === true),
        ];
    }

    public static function currentLabel(): string
    {
        $tenant = app(ActiveTenant::class)->model();

        if ($tenant === null) {
            return __('Global');
        }

        return $tenant->name;
    }
}
