<?php

namespace App\Modules\Supermarkets\Filament\RelationManagers;

use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Support\Countries;
use App\Support\CountryFilter;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class SupermarketProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supermarketProducts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Supermarket products');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->newQuery()->whereKey($ownerRecord->getKey())->exists()
            && (Gate::allows('view', $ownerRecord) || Gate::allows('update', $ownerRecord))
            && Gate::allows('viewAny', SupermarketProduct::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('Product'))->searchable()->sortable(),
                TextColumn::make('brand')->label(__('Brand'))->searchable()->placeholder('—'),
                TextColumn::make('origin')->label(__('Country of origin'))
                    ->formatStateUsing(fn (?string $state): ?string => Countries::label($state))->placeholder('—'),
                TextColumn::make('package_size')->label(__('Package size'))->numeric(),
                TextColumn::make('package_unit')->label(__('Unit')),
            ])
            ->filters([CountryFilter::make('origin')])
            ->headerActions([
                AttachAction::make()->label(__('Associate product'))->preloadRecordSelect()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord())),
            ])
            ->recordActions([
                DetachAction::make()
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->hidden(fn (SupermarketProduct $record): bool => $this->getOwnerRecord()
                        ->supermarketPrices()->where('supermarket_product_id', $record->getKey())->exists()),
            ])
            ->defaultSort('name');
    }
}
