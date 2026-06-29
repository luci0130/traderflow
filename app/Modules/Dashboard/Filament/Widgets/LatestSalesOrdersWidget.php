<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Support\StatusColors;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestSalesOrdersWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isSuperAdmin() || $user?->isSalesAgent()) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Latest sales orders'))
            ->query(fn (): Builder => app(DashboardScope::class)
                ->applyTo(SalesOrder::query())
                ->with('customer')
                ->latest())
            ->columns([
                TextColumn::make('order_number')->placeholder('—'),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('order_date')->date()->placeholder('—'),
                TextColumn::make('total')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
