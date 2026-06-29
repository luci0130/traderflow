<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers;

use App\Modules\Products\Models\Product;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
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
                    ->searchable()
                    // The sold product is fixed once the line exists; it can only be
                    // chosen when manually adding a new line.
                    ->disabledOn('edit'),
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()
                        ->visibleToTenant($this->getOwnerRecord()->tenant_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                Select::make('supplier_offer_item_id')
                    ->label('Supplier offer item')
                    ->options(fn (): array => SupplierOfferItem::query()
                        ->where('tenant_id', $this->getOwnerRecord()->tenant_id)
                        ->with(['product', 'supplierOffer.supplier'])
                        ->get()
                        ->mapWithKeys(fn (SupplierOfferItem $item): array => [
                            $item->id => "{$item->product?->name} - {$item->supplierOffer?->supplier?->name} ({$item->purchase_price} {$item->currency})",
                        ])
                        ->all())
                    ->searchable(),
                Select::make('supplier_product_id')
                    ->label(__('Producer product'))
                    ->relationship('supplierProduct', 'name')
                    ->searchable()
                    ->preload()
                    // The buy source (supplier listing) is set when the line is created
                    // and shouldn't be re-picked here.
                    ->disabledOn('edit'),
                TextInput::make('quantity')->numeric(),
                TextInput::make('purchase_price')->numeric(),
                TextInput::make('sale_price')->numeric()->required(),
                TextInput::make('margin_value')->numeric(),
                TextInput::make('margin_percent')->numeric(),
                TextInput::make('tax_rate')->numeric()->default(0),
                TextInput::make('line_total')->numeric()->default(0),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        // Customer offer items have no per-item currency; they follow the offer's
        // currency, so format every money column with it instead of a fixed EUR.
        $offerCurrency = $this->getOwnerRecord()->currency ?? 'EUR';

        return $table
            ->columns([
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('supplier.name')->searchable(),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('purchase_price')->money($offerCurrency)->sortable(),
                TextColumn::make('sale_price')->money($offerCurrency)->sortable(),
                TextColumn::make('margin_percent')->numeric(),
                TextColumn::make('line_total')->money($offerCurrency)->sortable(),
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
