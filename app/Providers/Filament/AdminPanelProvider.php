<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPermissionsTenant;
use App\Modules\Dashboard\Filament\Pages\TenantDashboard;
use App\Support\LocaleOptions;
use App\Support\Tenancy\ActiveTenantMenuItems;
use App\Support\TutorialCatalog;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->navigationGroups([
                // Every entry must be a NavigationGroup object: Filament only
                // honours the configured group order when the first element is
                // a NavigationGroup (see NavigationManager). A bare string here
                // silently falls back to discovery order.
                NavigationGroup::make(__('Entities'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->collapsed(),
                NavigationGroup::make(__('Catalog'))
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->collapsed(),
                NavigationGroup::make(__('Purchasing'))
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->collapsed(),
                NavigationGroup::make(__('Sales'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->collapsed(),
                NavigationGroup::make(__('Analytics'))
                    ->icon(Heroicon::OutlinedChartBar)
                    ->collapsed(),
                NavigationGroup::make(__('Reports'))
                    ->icon(Heroicon::OutlinedDocumentChartBar)
                    ->collapsed(),
                NavigationGroup::make(__('Supermarkets'))
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->collapsed(),
                NavigationGroup::make(__('Administration'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsed(),
            ])
            ->userMenuItems([
                ...ActiveTenantMenuItems::filamentUserMenuItems(),
                ...LocaleOptions::filamentUserMenuItems(),
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => view('filament.tutorials-menu', [
                    'tutorials' => TutorialCatalog::forUser(auth()->user()),
                ])->render(),
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: app_path('Modules/ProductCategories/Filament/Resources'), for: 'App\Modules\ProductCategories\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Units/Filament/Resources'), for: 'App\Modules\Units\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Products/Filament/Resources'), for: 'App\Modules\Products\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Suppliers/Filament/Resources'), for: 'App\Modules\Suppliers\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Customers/Filament/Resources'), for: 'App\Modules\Customers\Filament\Resources')
            ->discoverResources(in: app_path('Modules/SupplierOffers/Filament/Resources'), for: 'App\Modules\SupplierOffers\Filament\Resources')
            ->discoverResources(in: app_path('Modules/CustomerOffers/Filament/Resources'), for: 'App\Modules\CustomerOffers\Filament\Resources')
            ->discoverResources(in: app_path('Modules/SalesOrders/Filament/Resources'), for: 'App\Modules\SalesOrders\Filament\Resources')
            ->discoverResources(in: app_path('Modules/SupplierOrders/Filament/Resources'), for: 'App\Modules\SupplierOrders\Filament\Resources')
            ->discoverResources(in: app_path('Modules/NumberSequences/Filament/Resources'), for: 'App\Modules\NumberSequences\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Supermarkets/Filament/Resources'), for: 'App\Modules\Supermarkets\Filament\Resources')
            ->discoverResources(in: app_path('Modules/MarketComparison/Filament/Resources'), for: 'App\Modules\MarketComparison\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(in: app_path('Modules/BestPrices/Filament/Pages'), for: 'App\Modules\BestPrices\Filament\Pages')
            ->discoverPages(in: app_path('Modules/Supermarkets/Filament/Pages'), for: 'App\Modules\Supermarkets\Filament\Pages')
            ->discoverPages(in: app_path('Modules/MarketComparison/Filament/Pages'), for: 'App\Modules\MarketComparison\Filament\Pages')
            ->discoverPages(in: app_path('Modules/Reports/Filament/Pages'), for: 'App\Modules\Reports\Filament\Pages')
            ->pages([
                TenantDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SetLocale::class,
                SetPermissionsTenant::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup(__('Administration'))
                    ->navigationLabel(__('Roles'))
                    ->navigationSort(80)
                    ->navigationIcon(Heroicon::OutlinedShieldCheck),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Vite-backed theme and module assets are only available once the
        // frontend has been built (manifest present). During the Docker image
        // build, artisan boots before `npm run build`, so skip them then.
        if (file_exists(public_path('build/manifest.json'))) {
            $panel
                ->viteTheme('resources/css/filament/admin/theme.css')
                ->assets([
                    Js::make('chart-js-plugins', Vite::asset('resources/js/filament-chart-js-plugins.js'))->module(),
                    Js::make('sidebar-accordion', Vite::asset('resources/js/filament-sidebar-accordion.js'))->module(),
                ]);
        }

        return $panel;
    }
}
