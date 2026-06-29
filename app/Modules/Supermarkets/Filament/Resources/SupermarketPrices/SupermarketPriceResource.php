<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketPrices;

use App\Modules\Customers\Models\Customer;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\CreateSupermarketPrice;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\EditSupermarketPrice;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\ListSupermarketPrices;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupermarketPriceResource extends Resource
{
    protected static ?string $model = SupermarketPrice::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Supermarkets';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Supermarket price');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supermarket prices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Supermarkets');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('supermarket_id')
                            ->label('Supermarket')
                            ->options(fn (): array => Customer::query()->withoutGlobalScope('active_tenant')->global()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Select::make('supermarket_product_id')
                            ->label('Product')
                            ->options(fn (): array => SupermarketProduct::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('price')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->required(),
                        Select::make('currency')
                            ->options([
                                'RON' => 'RON',
                                'EUR' => 'EUR',
                                'USD' => 'USD',
                            ])
                            ->default('RON')
                            ->required(),
                        Toggle::make('is_promo')
                            ->label('On promotion')
                            ->live(),
                        TextInput::make('promo_price')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => (bool) $get('is_promo')),
                        DatePicker::make('observed_at')
                            ->label('Observed on')
                            ->default(today())
                            ->required(),
                        Select::make('source')
                            ->options([
                                SupermarketPrice::SOURCE_PHOTO => 'Photo',
                                SupermarketPrice::SOURCE_SCRAPER => 'Scraper',
                                SupermarketPrice::SOURCE_MANUAL => 'Manual',
                            ])
                            ->default(SupermarketPrice::SOURCE_MANUAL)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('product'))
            ->columns([
                TextColumn::make('observed_at')
                    ->label('Observed')
                    ->date()
                    ->sortable(),
                TextColumn::make('supermarket.name')
                    ->label('Supermarket')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Price incl. VAT')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('price_excl_vat')
                    ->label('Price excl. VAT')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('currency'),
                TextColumn::make('product.vat_rate')
                    ->label('VAT')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->toggleable(),
                IconColumn::make('is_promo')
                    ->boolean()
                    ->label('Promo'),
                TextColumn::make('promo_price')
                    ->label('Promo price incl. VAT')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('promo_price_excl_vat')
                    ->label('Promo price excl. VAT')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('supermarket_id')
                    ->label('Supermarket')
                    ->relationship('supermarket', 'name')
                    ->searchable(),
                SelectFilter::make('source')
                    ->options([
                        SupermarketPrice::SOURCE_PHOTO => 'Photo',
                        SupermarketPrice::SOURCE_SCRAPER => 'Scraper',
                        SupermarketPrice::SOURCE_MANUAL => 'Manual',
                    ]),
                Filter::make('observed_at')
                    ->schema([
                        DatePicker::make('from')->label('Observed from'),
                        DatePicker::make('until')->label('Observed until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('observed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('observed_at', '<=', $date))),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('observed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupermarketPrices::route('/'),
            'create' => CreateSupermarketPrice::route('/create'),
            'edit' => EditSupermarketPrice::route('/{record}/edit'),
        ];
    }
}
