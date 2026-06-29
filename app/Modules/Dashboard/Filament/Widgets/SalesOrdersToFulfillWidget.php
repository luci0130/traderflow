<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Support\StatusColors;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SalesOrdersToFulfillWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSalesAgent() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Sales orders to fulfill'))
            ->query(fn (): Builder => app(DashboardScope::class)
                ->applyTo(SalesOrder::query())
                ->with('customer')
                ->whereIn('status', ['draft', 'confirmed', 'in_preparation'])
                ->orderBy('delivery_date'))
            ->columns([
                TextColumn::make('order_number')->placeholder('—'),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('delivery_date')->date()->placeholder('—'),
                TextColumn::make('total')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
