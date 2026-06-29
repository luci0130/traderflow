<?php

namespace App\Modules\SalesOrders\Filament\Resources\SalesOrders\RelationManagers;

use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('tenant_id')
                    ->default(fn (): int => $this->getOwnerRecord()->tenant_id),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable(),
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()
                        ->visibleToTenant($this->getOwnerRecord()->tenant_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                Select::make('supplier_product_id')
                    ->label(__('Producer product'))
                    ->relationship('supplierProduct', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('unit_id')
                    ->label('Unit')
                    ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('quantity')->numeric(),
                TextInput::make('purchase_price')->numeric(),
                TextInput::make('sale_price')->numeric()->required(),
                TextInput::make('margin_value')->numeric(),
                TextInput::make('margin_percent')->numeric(),
                TextInput::make('line_total')->numeric()->default(0),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('supplier.name')->searchable(),
                TextColumn::make('unit.symbol'),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('purchase_price')->money('EUR')->sortable(),
                TextColumn::make('sale_price')->money('EUR')->sortable(),
                TextColumn::make('margin_value')
                    ->label(__('Profit / kg'))
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('profit_line')
                    ->label(__('Profit'))
                    ->money('EUR')
                    ->getStateUsing(fn (SalesOrderItem $record): float => (float) $record->margin_value * (float) $record->quantity),
                TextColumn::make('margin_percent')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('line_total')->money('EUR')->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
