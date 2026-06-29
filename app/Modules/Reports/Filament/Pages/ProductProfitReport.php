<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\SupermarketMarginReport;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Profit report grouped per product across all sold sales order items: how much
 * of each product was sold (quantity), the revenue, cost and profit it produced.
 */
class ProductProfitReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'reports/product-profit';

    protected Width|string|null $maxContentWidth = 'full';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return __('Profit per product');
    }

    public static function getNavigationLabel(): string
    {
        return __('Profit per product');
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
            ->columns([
                TextColumn::make('product_name')
                    ->label(__('Product'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('products.name', 'like', "%{$search}%"))
                    ->sortable(),
                TextColumn::make('quantity_sold')
                    ->label(__('Quantity sold'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('orders_count')
                    ->label(__('Orders'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('revenue_total')
                    ->label(__('Revenue'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('cost_total')
                    ->label(__('Cost'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('profit_total')
                    ->label(__('Profit'))
                    ->numeric(decimalPlaces: 2)
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('margin_percent')
                    ->label(__('Profit margin'))
                    ->state(fn (SalesOrderItem $record): string => $this->formatMargin($record))
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('marginChart')
                    ->label(__('Accepted / rejected margins'))
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->url(fn (SalesOrderItem $record): string => SupermarketMarginReport::getUrl(['product' => $record->getKey()])),
            ])
            ->defaultSort('profit_total', 'desc');
    }

    public function getQuery(): Builder
    {
        return SalesOrderItem::query()
            ->join('products', 'products.id', '=', 'sales_order_items.product_id')
            ->selectRaw('sales_order_items.product_id as id')
            ->selectRaw('MAX(products.name) as product_name')
            ->selectRaw('SUM(sales_order_items.quantity) as quantity_sold')
            ->selectRaw('SUM(sales_order_items.line_total) as revenue_total')
            ->selectRaw('SUM(sales_order_items.quantity * sales_order_items.purchase_price) as cost_total')
            ->selectRaw('SUM(sales_order_items.quantity * sales_order_items.margin_value) as profit_total')
            ->selectRaw('COUNT(DISTINCT sales_order_items.sales_order_id) as orders_count')
            ->groupBy('sales_order_items.product_id');
    }

    protected function formatMargin(SalesOrderItem $record): string
    {
        $cost = (float) $record->cost_total;

        if ($cost <= 0.0) {
            return '-';
        }

        return number_format((float) $record->profit_total / $cost * 100, 1).'%';
    }
}
