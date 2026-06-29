<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Support\StatusColors;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringCustomerOffersWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSalesAgent() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Customer offers expiring soon'))
            ->query(fn (): Builder => app(DashboardScope::class)
                ->applyTo(CustomerOffer::query())
                ->with('customer')
                ->whereIn('status', ['draft', 'sent'])
                ->whereNotNull('valid_until')
                ->whereBetween('valid_until', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->orderBy('valid_until'))
            ->columns([
                TextColumn::make('offer_number')->placeholder('—'),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('valid_until')->date()->placeholder('—'),
                TextColumn::make('total')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
