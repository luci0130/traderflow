<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\SalesOrders\Models\SalesOrder;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use App\Support\StatusColors;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Profit report grouped per sales order: revenue, sourcing cost and profit
 * (margin_value × quantity) aggregated from each order's line items.
 */
class SalesOrderProfitReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'reports/sales-order-profit';

    protected Width|string|null $maxContentWidth = 'full';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return __('Profit per sales order');
    }

    public static function getNavigationLabel(): string
    {
        return __('Profit per sales order');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getQuery())
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('Order number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(__('Customer'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order_date')
                    ->label(__('Order date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (?string $state): array => StatusColors::badge($state))
                    ->sortable(),
                TextColumn::make('revenue_total')
                    ->label(__('Revenue'))
                    ->money(fn (SalesOrder $record): string => $record->currency ?? 'EUR')
                    ->sortable(),
                TextColumn::make('cost_total')
                    ->label(__('Cost'))
                    ->money(fn (SalesOrder $record): string => $record->currency ?? 'EUR')
                    ->sortable(),
                TextColumn::make('profit_total')
                    ->label(__('Profit'))
                    ->money(fn (SalesOrder $record): string => $record->currency ?? 'EUR')
                    ->color(fn (SalesOrder $record): string => (float) $record->profit_total >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('margin_percent')
                    ->label(__('Profit margin'))
                    ->state(fn (SalesOrder $record): string => $this->formatMargin($record))
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('profit_total', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'draft' => __('Draft'),
                        'confirmed' => __('Confirmed'),
                        'in_preparation' => __('In preparation'),
                        'delivered' => __('Delivered'),
                        'invoiced' => __('Invoiced'),
                        'paid' => __('Paid'),
                        'cancelled' => __('Cancelled'),
                    ]),
                Filter::make('order_date')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('To')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('order_date', '<=', $date))),
            ]);
    }

    public function getQuery(): Builder
    {
        return SalesOrder::query()
            ->with('customer')
            ->select('sales_orders.*')
            ->selectRaw('COALESCE((SELECT SUM(line_total) FROM sales_order_items WHERE sales_order_items.sales_order_id = sales_orders.id), 0) as revenue_total')
            ->selectRaw('COALESCE((SELECT SUM(quantity * purchase_price) FROM sales_order_items WHERE sales_order_items.sales_order_id = sales_orders.id), 0) as cost_total')
            ->selectRaw('COALESCE((SELECT SUM(quantity * margin_value) FROM sales_order_items WHERE sales_order_items.sales_order_id = sales_orders.id), 0) as profit_total');
    }

    protected function formatMargin(SalesOrder $record): string
    {
        $cost = (float) $record->cost_total;

        if ($cost <= 0.0) {
            return '-';
        }

        return number_format((float) $record->profit_total / $cost * 100, 1).'%';
    }
}
