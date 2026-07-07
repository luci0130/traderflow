<?php

namespace App\Modules\Producers\Filament\Resources\ProducerOrders;

use App\Modules\Producers\Filament\Resources\ProducerOrders\Pages\ListProducerOrders;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProducerOrderResource extends Resource
{
    protected static ?string $model = SalesOrderItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Orders');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->producer_id !== null;
    }

    public static function getEloquentQuery(): Builder
    {
        $producerId = auth()->user()?->producer_id;

        return SalesOrderItem::query()
            ->whereHas('supplierProduct', fn (Builder $q) => $q->where('producer_id', $producerId))
            ->with(['salesOrder.customer', 'supplierProduct'])
            ->withoutGlobalScopes();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('salesOrder.order_number')->label(__('Order #'))->searchable()->sortable(),
                TextColumn::make('salesOrder.order_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('salesOrder.customer.name')->label(__('Customer'))->searchable(),
                TextColumn::make('supplierProduct.name')->label(__('Product'))->searchable(),
                TextColumn::make('quantity')->label(__('Quantity'))->numeric(decimalPlaces: 2),
                TextColumn::make('sale_price')->label(__('Price'))->numeric(decimalPlaces: 2),
                TextColumn::make('salesOrder.currency')->label(__('Currency')),
                TextColumn::make('salesOrder.status')->label(__('Status'))->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('salesOrder', 'status')
                    ->options([
                        'draft' => __('Draft'),
                        'confirmed' => __('Confirmed'),
                        'shipped' => __('Shipped'),
                        'delivered' => __('Delivered'),
                        'cancelled' => __('Cancelled'),
                    ]),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('to')->label(__('To')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereHas('salesOrder', fn (Builder $sq) => $sq->whereDate('order_date', '>=', $date)),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $q, $date) => $q->whereHas('salesOrder', fn (Builder $sq) => $sq->whereDate('order_date', '<=', $date)),
                            );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducerOrders::route('/'),
        ];
    }
}
