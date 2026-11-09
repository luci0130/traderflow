<?php

namespace App\Filament\Resources\CurrencyExchangeRates;

use App\Filament\Resources\CurrencyExchangeRates\Pages\ListCurrencyExchangeRates;
use App\Models\CurrencyExchangeRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CurrencyExchangeRateResource extends Resource
{
    protected static ?string $model = CurrencyExchangeRate::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('Exchange rate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Exchange rates');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $currencies = array_combine(config('currency.currencies'), config('currency.currencies'));

        return $table
            ->defaultSort('rate_date', 'desc')
            ->columns([
                TextColumn::make('base_currency')->label(__('Base currency'))->sortable(),
                TextColumn::make('quote_currency')->label(__('Quote currency'))->sortable(),
                TextColumn::make('rate')->label(__('Exchange rate'))->numeric(decimalPlaces: 6),
                TextColumn::make('rate_date')->label(__('Rate date'))->date()->sortable(),
                TextColumn::make('source')->label(__('Source')),
                TextColumn::make('updated_at')->label(__('Updated'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('base_currency')->label(__('Base currency'))->options($currencies),
                SelectFilter::make('quote_currency')->label(__('Quote currency'))->options($currencies),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCurrencyExchangeRates::route('/')];
    }
}
