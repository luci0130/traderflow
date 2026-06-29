<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Widgets\SupermarketProductMarginChart;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Hosts the per-supermarket accepted/rejected product margin scatter chart. The
 * supermarket can be pre-selected through a `?supermarket=` query parameter.
 */
class SupermarketProductMarginReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'reports/supermarket-product-margins';

    protected Width|string|null $maxContentWidth = 'full';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return __('Accepted / rejected product margins');
    }

    public static function getNavigationLabel(): string
    {
        return __('Accepted / rejected product margins');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    /**
     * @return array<int, class-string>
     */
    public function getHeaderWidgets(): array
    {
        return [
            SupermarketProductMarginChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
