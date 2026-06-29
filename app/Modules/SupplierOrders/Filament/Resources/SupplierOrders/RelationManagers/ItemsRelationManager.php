<?php

namespace App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\RelationManagers;

use App\Modules\Products\Models\Product;
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
                    ->label(__('Product'))
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable(),
                Select::make('supplier_product_id')
                    ->label(__('Producer product'))
                    ->relationship('supplierProduct', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('unit_id')
                    ->label(__('Unit'))
                    ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('quantity')->numeric(),
                TextInput::make('purchase_price')->numeric()->required(),
                Select::make('currency')
                    ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                    ->default('EUR'),
                TextInput::make('line_total')->numeric()->default(0),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $orderCurrency = $this->getOwnerRecord()->currency ?? 'EUR';

        return $table
            ->columns([
                TextColumn::make('product.name')->label(__('Product'))->searchable(),
                TextColumn::make('unit.symbol')->label(__('Unit')),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('purchase_price')->money($orderCurrency)->sortable(),
                TextColumn::make('line_total')->money($orderCurrency)->sortable(),
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
