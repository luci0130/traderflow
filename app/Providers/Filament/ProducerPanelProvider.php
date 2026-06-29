<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Modules\Producers\Filament\Pages\CompanyProfile;
use App\Modules\Producers\Filament\Pages\ProducerDashboard;
use App\Modules\Producers\Filament\Pages\RegisterProducer;
use App\Modules\Producers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Support\LocaleOptions;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ProducerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('producer')
            ->path('producer')
            ->homeUrl(fn (): string => SupplierProductResource::getUrl('index'))
            ->viteTheme('resources/css/filament/producer/theme.css')
            ->login()
            ->registration(RegisterProducer::class)
            ->passwordReset()
            ->emailVerification()
            ->navigationGroups([
                __('Products'),
                __('Orders'),
                __('Account'),
            ])
            ->userMenuItems(LocaleOptions::filamentUserMenuItems())
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->brandName(__('TradeFlow Producers'))
            ->discoverResources(
                in: app_path('Modules/Producers/Filament/Resources/SupplierProducts'),
                for: 'App\Modules\Producers\Filament\Resources\SupplierProducts',
            )
            ->discoverResources(
                in: app_path('Modules/Producers/Filament/Resources/ProducerOrders'),
                for: 'App\Modules\Producers\Filament\Resources\ProducerOrders',
            )
            ->pages([
                ProducerDashboard::class,
                CompanyProfile::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SetLocale::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
