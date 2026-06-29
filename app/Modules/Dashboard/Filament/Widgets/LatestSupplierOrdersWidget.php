<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Support\StatusColors;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestSupplierOrdersWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isPurchasingAgent() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Latest supplier orders'))
            ->query(fn (): Builder => app(DashboardScope::class)
                ->applyTo(SupplierOrder::query())
                ->with('supplier')
                ->latest())
            ->columns([
                TextColumn::make('order_number')->placeholder('—'),
                TextColumn::make('supplier.name')->label('Supplier'),
                TextColumn::make('order_date')->date()->placeholder('—'),
                TextColumn::make('expected_date')->date()->placeholder('—'),
                TextColumn::make('total')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
