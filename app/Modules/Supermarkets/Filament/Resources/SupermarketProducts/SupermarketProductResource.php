<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketProducts;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\CanonicalProductResource;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\CreateSupermarketProduct;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\EditSupermarketProduct;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\ListSupermarketProducts;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Units\Models\Unit;
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use UnitEnum;

class SupermarketProductResource extends Resource
{
    protected static ?string $model = SupermarketProduct::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Supermarket product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supermarket products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function form(Schema $schema): Schema
    {
        $fields = static::buildFields();

        return $schema
            ->columns(2)
            ->components([
                Group::make()
                    ->columnSpan(1)
                    ->schema([
                        Section::make(__('Product'))
                            ->columns(2)
                            ->schema([
                                $fields['name'],
                                $fields['category'],
                                $fields['origin'],
                                $fields['is_bio'],
                            ]),
                        Section::make(__('Packaging'))
                            ->columns(3)
                            ->schema([
                                $fields['packaging_method_id'],
                                $fields['package_size'],
                                $fields['package_unit'],
                            ]),
                    ]),
                Group::make()
                    ->columnSpan(1)
                    ->schema([
                        Section::make(__('Advanced details'))
                            ->collapsible()
                            ->columns(2)
                            ->schema([
                                $fields['status'],
                                $fields['brand'],
                                $fields['caliber'],
                                $fields['quality'],
                                $fields['description'],
                                $fields['barcode'],
                                $fields['vat_rate'],
                                $fields['image_path'],
                            ]),
                    ]),
            ]);
    }

    /**
     * Reusable flat field list, also used by the photo review page when creating
     * a product on the fly and by the customer products relation manager.
     *
     * @return array<int, mixed>
     */
    public static function formComponents(bool $compact = false, bool $includeImage = true): array
    {
        return array_values(static::buildFields($compact, $includeImage));
    }

    /**
     * Builds every form field keyed by name so the same definitions back both the
     * sectioned layout and the flat (compact) layout.
     *
     * @return array<string, mixed>
     */
    protected static function buildFields(bool $compact = false, bool $includeImage = true): array
    {
        $name = TextInput::make('name')
            ->label(__('Name'))
            ->required()
            ->maxLength(255);
        $category = Select::make('category')
            ->label(__('Category'))
            ->options(fn (): array => static::categoryOptions())
            ->searchable();
        $origin = Select::make('origin')
            ->label(__('Country of origin'))
            ->options(Countries::options())
            ->searchable()
            ->default('RO');
        $bio = Toggle::make('is_bio')
            ->label(__('Bio (organic)'))
            ->inline(false);
        $status = Select::make('status')
            ->label(__('Status'))
            ->options([
                'active' => __('Active'),
                'archived' => __('Archived'),
            ])
            ->default('active')
            ->required();
        $brand = TextInput::make('brand')
            ->label(__('Variety/Brand'))
            ->maxLength(255);
        $caliber = TextInput::make('caliber')
            ->label(__('Caliber'))
            ->maxLength(255);
        $quality = TextInput::make('quality')
            ->label(__('Quality'))
            ->maxLength(255);
        $description = Textarea::make('description')
            ->label(__('Description'))
            ->columnSpanFull();
        $barcode = TextInput::make('barcode')
            ->label(__('Barcode (EAN)'))
            ->maxLength(255);
        $vatRate = TextInput::make('vat_rate')
            ->label(__('VAT rate'))
            ->helperText(__('Recorded prices include this VAT rate.'))
            ->numeric()
            ->step('0.01')
            ->minValue(0)
            ->maxValue(100)
            ->suffix('%')
            ->default(SupermarketProduct::DEFAULT_VAT_RATE)
            ->afterStateHydrated(function (TextInput $component, mixed $state): void {
                if ($state === null || $state === '') {
                    $component->state(SupermarketProduct::DEFAULT_VAT_RATE);
                }
            })
            ->required();
        $packagingMethod = Select::make('packaging_method_id')
            ->label(__('Packaging method'))
            ->options(fn (): array => static::packagingMethodOptions())
            ->default(fn (): ?int => PackagingMethod::query()->where('name', 'Vrac')->value('id'))
            ->searchable()
            ->preload();
        $packageSize = TextInput::make('package_size')
            ->label(__('Package size'))
            ->numeric()
            ->step('0.0001')
            ->minValue(0);
        $packageUnit = Select::make('package_unit')
            ->label(__('Unit'))
            ->options(fn (): array => static::unitOptions())
            ->default('kg')
            ->searchable()
            ->required();
        $image = FileUpload::make('image_path')
            ->label(__('Photo'))
            ->image()
            ->disk('public')
            ->directory('supermarket-products')
            ->maxSize(5120)
            ->columnSpanFull();

        if ($compact) {
            // Fast photo-transcription flow: keep free text for the values read off
            // a label (any category / country / unit wording) and drop the
            // curated-only Status field.
            $category = TextInput::make('category')
                ->label(__('Category'))
                ->hiddenLabel()
                ->placeholder(__('Category'))
                ->maxLength(255);
            $origin = TextInput::make('origin')
                ->label(__('Country of origin'))
                ->hiddenLabel()
                ->placeholder(__('Origin'))
                ->maxLength(255);
            $packageUnit = TextInput::make('package_unit')
                ->label(__('Unit'))
                ->hiddenLabel()
                ->placeholder(__('Unit'))
                ->maxLength(16);

            $name->hiddenLabel()->placeholder(__('Product name'))->columnSpan(['default' => 1, 'md' => 2]);
            $brand->hiddenLabel()->placeholder(__('Variety/Brand'));
            $caliber->hiddenLabel()->placeholder(__('Caliber'));
            $quality->hiddenLabel()->placeholder(__('Quality'));
            $barcode->hiddenLabel()->placeholder(__('Barcode'));
            $vatRate->hiddenLabel()->helperText(null)->placeholder(__('VAT'));
            $packagingMethod->hiddenLabel()->placeholder(__('Packaging'));
            $packageSize->hiddenLabel()->placeholder(__('Package size'));
            $bio->inline(true);
        }

        $fields = [
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'origin' => $origin,
            'caliber' => $caliber,
            'quality' => $quality,
            'barcode' => $barcode,
            'packaging_method_id' => $packagingMethod,
            'package_size' => $packageSize,
            'package_unit' => $packageUnit,
            'vat_rate' => $vatRate,
            'is_bio' => $bio,
        ];

        if (! $compact) {
            $fields['status'] = $status;
            $fields['description'] = $description;
        }

        if ($includeImage) {
            $fields['image_path'] = $image;
        }

        return $fields;
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
     * Units keyed by symbol (the stored value) and labelled "Name (symbol)",
     * merged with any free-text units already stored so legacy values stay selectable.
     *
     * @return array<string, string>
     */
    public static function unitOptions(): array
    {
        return Unit::query()
            ->withoutGlobalScopes()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Unit $unit): array => [$unit->symbol => $unit->name.' ('.$unit->symbol.')'])
            ->all()
            + ['kg' => __('Kilogram').' (kg)']
            + SupermarketProduct::query()
                ->whereNotNull('package_unit')
                ->where('package_unit', '!=', '')
                ->distinct()
                ->pluck('package_unit', 'package_unit')
                ->all();
    }

    /**
     * Category names drawn from the shared product-category taxonomy, merged with
     * any category values already stored on products so legacy values stay selectable.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return ProductCategory::query()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->merge(
                SupermarketProduct::query()
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->pluck('category', 'category'),
            )
            ->unique()
            ->sort()
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('display_image_path')
                    ->label('Photo')
                    ->disk('public')
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_bio')
                    ->label('Bio')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusSmall)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),
                TextColumn::make('brand')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('category')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('origin')
                    ->formatStateUsing(fn (?string $state): ?string => Countries::label($state))
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('caliber')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('quality')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('barcode')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('package_size')
                    ->label('Package')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SupermarketProduct $record): string => $record->package_unit ? ' '.$record->package_unit : '')
                    ->placeholder('-'),
                TextColumn::make('packagingMethod.name')
                    ->label('Packaging')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('vat_rate')
                    ->label('VAT')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->toggleable(),
                TextColumn::make('prices_count')
                    ->label('Prices')
                    ->counts('prices')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_bio')->label('Bio'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                static::mapToCanonicalBulkAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Bulk action: map the selected supermarket products to an existing canonical
     * product, or create a new canonical (with the full canonical form) and map
     * them to it. Each product belongs to a single canonical, so already-grouped
     * products are moved.
     */
    public static function mapToCanonicalBulkAction(): BulkAction
    {
        return BulkAction::make('mapToCanonical')
            ->label(__('Map to canonical product'))
            ->icon(Heroicon::OutlinedSquare3Stack3d)
            ->color('gray')
            ->schema([
                Select::make('canonical_product_id')
                    ->label(__('Canonical product'))
                    ->options(fn (): array => CanonicalProduct::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->helperText(__('Pick an existing group or create a new one for the selected products.'))
                    ->createOptionForm(CanonicalProductResource::formComponents())
                    ->createOptionUsing(fn (array $data): int => CanonicalProduct::create($data)->getKey()),
            ])
            ->action(function (EloquentCollection $records, array $data): void {
                $canonical = CanonicalProduct::find($data['canonical_product_id']);

                if ($canonical === null) {
                    return;
                }

                $ids = $records->pluck('id')->all();

                // One product = one canonical: detach from any other group first.
                SupermarketProduct::query()->whereKey($ids)->get()
                    ->each(fn (SupermarketProduct $product) => $product->canonicalProducts()->detach());

                $canonical->supermarketProducts()->syncWithoutDetaching($ids);

                Notification::make()
                    ->title(__(':count products mapped to :name', ['count' => count($ids), 'name' => $canonical->name]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupermarketProducts::route('/'),
            'create' => CreateSupermarketProduct::route('/create'),
            'edit' => EditSupermarketProduct::route('/{record}/edit'),
        ];
    }
}
