<?php

namespace App\Modules\Supermarkets\Filament\RelationManagers;

use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Support\Countries;
use App\Support\CountryFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class SupermarketPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'supermarketPrices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Supermarket price history');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->newQuery()->whereKey($ownerRecord->getKey())->exists()
            && (Gate::allows('view', $ownerRecord) || Gate::allows('update', $ownerRecord))
            && Gate::allows('viewAny', SupermarketPrice::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'supermarket']))
            ->columns([
                TextColumn::make('product.name')->label(__('Product'))->searchable()->sortable(),
                TextColumn::make('product.brand')->label(__('Brand'))->placeholder('—'),
                TextColumn::make('product.origin')->label(__('Country of origin'))
                    ->formatStateUsing(fn (?string $state): ?string => Countries::label($state))->placeholder('—'),
                TextColumn::make('supermarket.name')->label(__('Supermarket'))->searchable()->sortable(),
                TextColumn::make('observed_at')->label(__('Observed on'))->date()->sortable(),
                TextColumn::make('price')->label(__('Price incl. VAT'))->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('currency')->label(__('Currency')),
                IconColumn::make('is_promo')->label(__('Promo'))->boolean(),
                TextColumn::make('promo_price')->label(__('Promo price'))->numeric(decimalPlaces: 2)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('supermarket_product_id')->label(__('Product'))->relationship('product', 'name')->searchable(),
                SelectFilter::make('supermarket_id')->label(__('Supermarket'))->relationship('supermarket', 'name')->searchable(),
                CountryFilter::make('origin', 'product'),
                Filter::make('observed_at')->schema([
                    DatePicker::make('from')->label(__('Observed from')),
                    DatePicker::make('until')->label(__('Observed until')),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('observed_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('observed_at', '<=', $date))),
            ])
            ->defaultSort('observed_at', 'desc');
    }
}
