<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Widgets\SupermarketMarginChart;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Hosts the supermarket accepted/rejected margin scatter chart. The product can
 * be pre-selected through a `?product=` query parameter (used by the product
 * profit report row action).
 */
class SupermarketMarginReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'reports/supermarket-margins';

    protected Width|string|null $maxContentWidth = 'full';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return __('Accepted / rejected supermarket margins');
    }

    public static function getNavigationLabel(): string
    {
        return __('Accepted / rejected supermarket margins');
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
            SupermarketMarginChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
