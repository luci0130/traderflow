<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierProducts;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\CanonicalProductResource;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\CreateSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\EditSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use UnitEnum;

/**
 * Admin-wide catalog of every supplier's products (across all suppliers), with
 * tiered prices and per-product cost overrides. The per-supplier view lives on
 * the supplier edit page (ProductsRelationManager); this is the global listing.
 */
class SupplierProductResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Supplier product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier products');
    }

    public static function getNavigationLabel(): string
    {
        return __('Supplier products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Group::make()
                    ->columnSpan(1)
                    ->schema(static::leftColumnComponents()),
                Group::make()
                    ->columnSpan(1)
                    ->schema(static::rightColumnComponents()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    public static function leftColumnComponents(): array
    {
        return [
            Section::make(__('Supplier'))
                ->schema([
                    Select::make('producer_id')
                        ->label(__('Supplier'))
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
            Section::make(__('Product'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    Select::make('category')
                        ->label(__('Category'))
                        ->options(fn (): array => static::categoryOptions())
                        ->searchable(),
                    Select::make('country_of_origin')
                        ->label(__('Country of origin'))
                        ->options(Countries::options())
                        ->searchable()
                        ->default('RO'),
                    Toggle::make('is_bio')
                        ->label(__('Eco (organic)'))
                        ->inline(false),
                ]),
            Section::make(__('Advanced details'))
                ->collapsed()
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label(__('Status'))
                        ->options([
                            'active' => __('Active'),
                            'archived' => __('Archived'),
                        ])
                        ->default('active')
                        ->required(),
                    TextInput::make('variety')->label(__('Variety'))->maxLength(255),
                    TextInput::make('caliber')->label(__('Caliber'))->maxLength(255),
                    TextInput::make('quality')->label(__('Quality'))->maxLength(255),
                    Textarea::make('description')->label(__('Description'))->columnSpanFull(),
                    FileUpload::make('image_path')
                        ->label(__('Photo'))
                        ->image()
                        ->disk('public')
                        ->directory('supplier-products')
                        ->maxSize(5120)
                        ->columnSpanFull(),
                ]),
            Section::make(__('Packaging'))
                ->columns(3)
                ->schema([
                    Select::make('packaging_method_id')
                        ->label(__('Packaging method'))
                        ->options(fn (): array => static::packagingMethodOptions())
                        ->default(fn (): ?int => PackagingMethod::query()->where('name', 'Vrac')->value('id'))
                        ->searchable()
                        ->preload(),
                    TextInput::make('package_size')
                        ->label(__('Package size'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0),
                    Select::make('min_quantity_unit')
                        ->label(__('Unit'))
                        ->options(fn (): array => static::unitOptions())
                        ->default('kg')
                        ->searchable()
                        ->required(),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function rightColumnComponents(): array
    {
        return [
            Section::make(__('Pricing'))
                ->columns(2)
                ->schema([
                    Select::make('currency')
                        ->label(__('Currency'))
                        ->options(static::currencyOptions())
                        ->default('EUR')
                        ->required(),
                    TextInput::make('min_quantity_value')
                        ->label(__('Minimum quantity'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->required(),
                    TextInput::make('unit_price')
                        ->label(__('Price per unit'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->required(),
                ]),
            Section::make(__('Tiered pricing'))
                ->description(__('Optional quantity breaks. The first row becomes the default price.'))
                ->collapsed()
                ->schema([
                    static::tieredPricingRepeater(),
                ]),
            Section::make(__('Costs (override)'))
                ->description(__('Leave blank to inherit the supplier default costs.'))
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('cost_override.packaging_cost')->label(__('Packaging cost'))->numeric()->step('0.0001')->minValue(0),
                    TextInput::make('cost_override.transport_cost')->label(__('Transport cost'))->numeric()->step('0.0001')->minValue(0),
                    TextInput::make('cost_override.commission')->label(__('Commission'))->numeric()->step('0.0001')->minValue(0),
                    TextInput::make('cost_override.profit_margin')->label(__('Profit margin'))->numeric()->step('0.0001')->minValue(0),
                ]),
            Section::make(__('Offer'))
                ->columns(2)
                ->schema([
                    TextInput::make('quantity_available')
                        ->label(__('Quantity available'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0),
                    DatePicker::make('valid_until')
                        ->label(__('Offer valid until'))
                        ->native(false)
                        ->suffixIcon(Heroicon::Calendar),
                ]),
        ];
    }

    public static function tieredPricingRepeater(): Repeater
    {
        return Repeater::make('prices')
            ->label(__('Quantity pricing'))
            ->table([
                TableColumn::make(__('Min qty')),
                TableColumn::make(__('Price / unit')),
            ])
            ->compact()
            // Force the table layout at the narrow right-column width (Filament
            // only switches to it at @xl/576px; see admin theme.css).
            ->extraAttributes(['class' => 'fi-tiered-pricing-table'])
            ->schema([
                TextInput::make('min_quantity_value')->hiddenLabel()->numeric()->step('0.0001')->minValue(0)->required(),
                TextInput::make('unit_price')->hiddenLabel()->numeric()->step('0.0001')->minValue(0)->required(),
            ])
            ->defaultItems(0)
            ->addActionLabel(__('Add price row'))
            ->reorderable(false);
    }

    /**
     * Replace the product's tiered prices and mirror the first row onto the
     * product's own default price columns (matching producer-panel behaviour).
     *
     * @param  array<int, array<string, mixed>>  $prices
     */
    public static function persistPriceBreaks(SupplierProduct $record, array $prices): void
    {
        $normalized = collect($prices)
            ->filter(fn (array $price): bool => filled($price['min_quantity_value'] ?? null) && filled($price['unit_price'] ?? null))
            ->values();

        $record->prices()->delete();

        $normalized->each(fn (array $price, int $index) => $record->prices()->create([
            'min_quantity_value' => $price['min_quantity_value'],
            'unit_price' => $price['unit_price'],
            'sort_order' => $index,
        ]));

        $first = $normalized->first();

        if ($first !== null) {
            $record->forceFill([
                'min_quantity_value' => $first['min_quantity_value'],
                'unit_price' => $first['unit_price'],
            ])->save();
        }
    }

    /**
     * Upsert (or clear) the per-product cost override. A fully blank set is
     * treated as "inherit the supplier default" and removes any existing row.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function persistCostOverride(SupplierProduct $record, ?array $data): void
    {
        $values = [];

        foreach (['packaging_cost', 'transport_cost', 'commission', 'profit_margin'] as $field) {
            $value = $data[$field] ?? null;
            $values[$field] = ($value === '' || $value === null) ? null : $value;
        }

        $hasAnyValue = collect($values)->contains(fn ($value): bool => $value !== null);

        if (! $hasAnyValue) {
            $record->costOverride()->delete();

            return;
        }

        $record->costOverride()->updateOrCreate([], $values);
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
     * Units keyed by symbol (the stored value) and labelled "Name (symbol)".
     * Deduplicated by symbol so the same unit shared across tenants appears once.
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
            ->all() + ['kg' => __('Kilogram').' (kg)'];
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
                SupplierProduct::query()
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->pluck('category', 'category'),
            )
            ->unique()
            ->sort()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        return [
            'EUR' => 'EUR',
            'RON' => 'RON',
            'USD' => 'USD',
            'GBP' => 'GBP',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->label(__('Photo'))->disk('public')->square()->size(48),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                IconColumn::make('is_bio')
                    ->label(__('Bio'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusSmall)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),
                TextColumn::make('packagingMethod.name')->label(__('Packaging'))->placeholder('-')->sortable()->toggleable(),
                TextColumn::make('unit_price')->label(__('Price / unit'))->numeric(decimalPlaces: 4)->sortable(),
                TextColumn::make('currency')->label(__('Currency'))->sortable()->toggleable(),
                TextColumn::make('min_quantity_value')
                    ->label(__('Min qty'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SupplierProduct $record): string => $record->min_quantity_unit ? ' '.$record->min_quantity_unit : '')
                    ->toggleable(),
                TextColumn::make('prices_count')->label(__('Tiers'))->counts('prices')->sortable()->toggleable(),
                TextColumn::make('valid_until')->label(__('Valid until'))->date()->sortable()->placeholder('-'),
                IconColumn::make('is_offer_valid')
                    ->label(__('Active'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->filters([
                SelectFilter::make('producer_id')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        'active' => __('Active'),
                        'archived' => __('Archived'),
                    ]),
                TernaryFilter::make('is_bio')->label(__('Bio')),
                TernaryFilter::make('valid')
                    ->label(__('Currently valid'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Valid'))
                    ->falseLabel(__('Expired'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('status', 'active')->whereDate('valid_until', '>=', today()),
                        false: fn (Builder $q) => $q->where(fn (Builder $sub) => $sub->where('status', '!=', 'active')->orWhereDate('valid_until', '<', today())),
                    ),
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
     * Bulk action: map the selected supplier products to an existing canonical
     * product, or create a new canonical (via the inline "create option") and map
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
                SupplierProduct::query()->whereKey($ids)->get()
                    ->each(fn (SupplierProduct $product) => $product->canonicalProducts()->detach());

                $canonical->supplierProducts()->syncWithoutDetaching($ids);

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
            'index' => ListSupplierProducts::route('/'),
            'create' => CreateSupplierProduct::route('/create'),
            'edit' => EditSupplierProduct::route('/{record}/edit'),
        ];
    }
}
