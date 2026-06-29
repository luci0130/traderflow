<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages\CreateCanonicalProduct;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages\EditCanonicalProduct;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages\ListCanonicalProducts;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers\SupermarketProductsRelationManager;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers\SupplierProductsRelationManager;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CanonicalProductResource extends Resource
{
    protected static ?string $model = CanonicalProduct::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Canonical product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Canonical products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema(static::formComponents())
                    ->columns(2),
            ]);
    }

    /**
     * The full set of canonical-product fields, reused by the "create canonical"
     * option on the supplier/supermarket bulk-mapping actions so they stay in sync.
     *
     * @return array<int, mixed>
     */
    public static function formComponents(): array
    {
        return [
            Select::make('product_category_id')
                ->label(__('Category'))
                ->options(fn (): array => static::categoryOptions())
                ->searchable(),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('variety')
                ->label(__('Variety'))
                ->maxLength(255),
            Select::make('country_of_origin')
                ->label(__('Country of origin'))
                ->options(Countries::options())
                ->searchable(),
            TextInput::make('caliber')
                ->label(__('Caliber'))
                ->maxLength(255),
            Select::make('packaging_method_id')
                ->label(__('Packaging method'))
                ->options(fn (): array => static::packagingMethodOptions())
                ->searchable(),
            TextInput::make('package_size')
                ->label(__('Package size'))
                ->numeric()
                ->step('0.0001')
                ->minValue(0)
                ->placeholder('4'),
            TextInput::make('package_unit')
                ->label(__('Unit'))
                ->maxLength(16)
                ->placeholder('kg / g / buc'),
            Textarea::make('notes')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function packagingMethodOptions(): array
    {
        return PackagingMethod::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return ProductCategory::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variety')
                    ->label(__('Variety'))
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('country_of_origin')
                    ->label(__('Country of origin'))
                    ->formatStateUsing(fn (?string $state): ?string => Countries::label($state))
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('caliber')
                    ->label(__('Caliber'))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('packaging_variant')
                    ->label(__('Packaging'))
                    ->placeholder('-'),
                TextColumn::make('supplier_products_count')
                    ->label(__('Supplier products'))
                    ->counts('supplierProducts')
                    ->badge()
                    ->sortable(),
                TextColumn::make('supermarket_products_count')
                    ->label(__('Supermarket products'))
                    ->counts('supermarketProducts')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => static::categoryOptions())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            SupplierProductsRelationManager::class,
            SupermarketProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCanonicalProducts::route('/'),
            'create' => CreateCanonicalProduct::route('/create'),
            'edit' => EditCanonicalProduct::route('/{record}/edit'),
        ];
    }
}
