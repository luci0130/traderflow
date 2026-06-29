<?php

namespace App\Modules\Customers\Filament\RelationManagers;

use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Supermarket products linked to this record through its recorded prices.
 * Read-only: products are created while recording prices, not from here.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->products()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema(SupermarketProductResource::formComponents())
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('image_path')
                    ->label(__('Photo'))
                    ->disk('public')
                    ->square()
                    ->size(40),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('brand')->label(__('Brand'))->searchable()->sortable()->placeholder('-')->toggleable(),
                TextColumn::make('category')->label(__('Category'))->searchable()->sortable()->placeholder('-'),
                TextColumn::make('package_size')
                    ->label(__('Package'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SupermarketProduct $record): string => $record->package_unit ? ' '.$record->package_unit : '')
                    ->placeholder('-'),
                TextColumn::make('vat_rate')
                    ->label(__('VAT'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
