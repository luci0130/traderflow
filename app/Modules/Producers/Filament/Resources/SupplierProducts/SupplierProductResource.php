<?php

namespace App\Modules\Producers\Filament\Resources\SupplierProducts;

use App\Modules\Producers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Units\Models\Unit;
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierProductResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Products';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('My products');
    }

    public static function getNavigationLabel(): string
    {
        return __('My products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function getEloquentQuery(): Builder
    {
        $producerId = auth()->user()?->producer_id;

        return parent::getEloquentQuery()->when(
            $producerId !== null,
            fn (Builder $query) => $query->where('producer_id', $producerId),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(static::productFormComponents());
    }

    /**
     * @return array<int, mixed>
     */
    public static function productFormComponents(bool $includePriceBreaks = false): array
    {
        return [
            Section::make(__('Product'))
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
                ])
                ->columns(2),
            Section::make(__('Advanced details'))
                ->collapsed()
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
                    TextInput::make('default_packaging')
                        ->label(__('Packaging details'))
                        ->placeholder(__('2kg bag, 10kg crate...'))
                        ->maxLength(255),
                    Textarea::make('description')->label(__('Description'))->columnSpanFull(),
                    FileUpload::make('image_path')
                        ->label(__('Photo'))
                        ->image()
                        ->disk('public')
                        ->directory('supplier-products')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Packaging'))
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
                        ->minValue(0)
                        ->suffix(fn (Get $get): string => static::unitSuffix($get)),
                    Select::make('min_quantity_unit')
                        ->label(__('Unit'))
                        ->options(fn (): array => static::unitOptions())
                        ->default('kg')
                        ->searchable()
                        ->live()
                        ->required(),
                ])
                ->columns(3),
            Section::make(__('Pricing'))
                ->schema([
                    Select::make('currency')
                        ->label(__('Currency'))
                        ->options(static::currencyOptions())
                        ->default('EUR')
                        ->live()
                        ->required(),
                    TextInput::make('min_quantity_value')
                        ->label(__('Minimum quantity'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->suffix(fn (Get $get): string => static::unitSuffix($get))
                        ->required(),
                    TextInput::make('unit_price')
                        ->label(__('Price per unit'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->suffix(fn (Get $get): string => static::currencyPerUnitSuffix($get))
                        ->required(),
                ])
                ->columns(2),
            Section::make(__('Advanced pricing'))
                ->collapsed()
                ->visible($includePriceBreaks)
                ->schema([
                    static::priceBreaksRepeater(),
                ]),
            Section::make(__('Offer'))
                ->schema([
                    TextInput::make('quantity_available')
                        ->label(__('Quantity available'))
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0)
                        ->suffix(fn (Get $get): string => static::unitSuffix($get)),
                    DatePicker::make('valid_until')
                        ->label(__('Offer valid until'))
                        ->native(false)
                        ->suffixIcon(Heroicon::Calendar)
                        ->minDate(today())
                        ->required(),
                ])
                ->columns(2),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function quickProductRowFields(): array
    {
        return [
            FileUpload::make('image_path')
                ->label(__('Photo'))
                ->image()
                ->disk('public')
                ->directory('supplier-products'),
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Select::make('packaging_method_id')
                ->label(__('Packaging'))
                ->options(fn (): array => static::packagingMethodOptions())
                ->default(fn (): ?int => PackagingMethod::query()->where('name', 'Vrac')->value('id'))
                ->searchable()
                ->preload(),
            TextInput::make('package_size')
                ->label(__('Package size'))
                ->numeric()
                ->step('0.0001')
                ->minValue(0)
                ->suffix(fn (Get $get): string => static::unitSuffix($get)),
            TextInput::make('quantity_available')
                ->label(__('Quantity available'))
                ->numeric()
                ->step('0.0001')
                ->minValue(0)
                ->suffix(fn (Get $get): string => static::unitSuffix($get)),
            DatePicker::make('valid_until')
                ->label(__('Offer valid until'))
                ->native(false)
                ->suffixIcon(Heroicon::Calendar)
                ->minDate(today())
                ->required(),
            Select::make('min_quantity_unit')
                ->label(__('Unit'))
                ->options(fn (): array => static::unitOptions())
                ->default('kg')
                ->searchable()
                ->live()
                ->required(),
            Select::make('currency')
                ->label(__('Currency'))
                ->options(static::currencyOptions())
                ->default('EUR')
                ->live()
                ->required(),
            TextInput::make('min_quantity_value')
                ->label(__('Minimum quantity'))
                ->numeric()
                ->step('0.0001')
                ->minValue(0)
                ->suffix(fn (Get $get): string => static::unitSuffix($get))
                ->required(),
            TextInput::make('unit_price')
                ->label(__('Price per unit'))
                ->numeric()
                ->step('0.0001')
                ->minValue(0)
                ->suffix(fn (Get $get): string => static::currencyPerUnitSuffix($get))
                ->required(),
        ];
    }

    public static function priceBreaksRepeater(): Repeater
    {
        return Repeater::make('prices')
            ->label(__('Quantity pricing'))
            ->table([
                TableColumn::make(__('Min qty'))->width('180px'),
                TableColumn::make(__('Price'))->width('180px'),
            ])
            ->schema([
                TextInput::make('min_quantity_value')
                    ->hiddenLabel()
                    ->numeric()
                    ->step('0.0001')
                    ->minValue(0)
                    ->suffix(fn (Get $get): string => static::unitSuffix($get))
                    ->required(),
                TextInput::make('unit_price')
                    ->hiddenLabel()
                    ->numeric()
                    ->step('0.0001')
                    ->minValue(0)
                    ->suffix(fn (Get $get): string => static::currencyPerUnitSuffix($get))
                    ->required(),
            ])
            ->defaultItems(1)
            ->addActionLabel(__('Add price row'))
            ->reorderable(false);
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

    public static function unitSuffix(Get $get): string
    {
        return static::resolveUnit($get);
    }

    public static function currencyPerUnitSuffix(Get $get): string
    {
        return static::resolveCurrency($get).'/'.static::resolveUnit($get);
    }

    /**
     * @param  array<int, array<string, mixed>>  $prices
     */
    public static function replacePriceBreaks(SupplierProduct $record, array $prices): void
    {
        $normalizedPrices = collect($prices)
            ->filter(fn (array $price): bool => filled($price['min_quantity_value'] ?? null) && filled($price['unit_price'] ?? null))
            ->values();

        $record->prices()->delete();

        $normalizedPrices->each(function (array $price, int $index) use ($record): void {
            $record->prices()->create([
                'min_quantity_value' => $price['min_quantity_value'],
                'unit_price' => $price['unit_price'],
                'sort_order' => $index,
            ]);
        });

        $firstPrice = $normalizedPrices->first();

        if ($firstPrice !== null) {
            $record->forceFill([
                'min_quantity_value' => $firstPrice['min_quantity_value'],
                'unit_price' => $firstPrice['unit_price'],
            ])->save();
        }
    }

    protected static function resolveUnit(Get $get): string
    {
        return filled($get('min_quantity_unit'))
            ? (string) $get('min_quantity_unit')
            : (filled($get('../../min_quantity_unit')) ? (string) $get('../../min_quantity_unit') : 'unit');
    }

    protected static function resolveCurrency(Get $get): string
    {
        return filled($get('currency'))
            ? (string) $get('currency')
            : (filled($get('../../currency')) ? (string) $get('../../currency') : 'EUR');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('display_image_path')
                    ->label(__('Photo'))
                    ->disk('public')
                    ->square()
                    ->size(48),
                TextInputColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                IconColumn::make('is_bio')
                    ->label(__('Bio'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusSmall)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->toggleable(),
                TextInputColumn::make('quantity_available')->label(__('Available')),
                TextColumn::make('packagingMethod.name')->label(__('Packaging'))->placeholder('-')->sortable(),
                TextInputColumn::make('min_quantity_value')->label(__('Min qty')),
                TextInputColumn::make('min_quantity_unit')->label(__('Unit')),
                TextInputColumn::make('unit_price')->label(__('Price / unit')),
                TextColumn::make('currency')->label(__('Currency'))->sortable()->toggleable(),
                TextColumn::make('valid_until')->label(__('Valid until'))->date()->sortable(),
                IconColumn::make('is_offer_valid')
                    ->label(__('Active'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => __('Active'),
                        'archived' => __('Archived'),
                    ]),
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
                Action::make('advanced')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->slideOver()
                    ->modalHeading(fn (SupplierProduct $record): string => __('Edit :name', ['name' => $record->name]))
                    ->fillForm(fn (SupplierProduct $record): array => $record->only([
                        'name', 'description', 'variety', 'country_of_origin', 'caliber', 'quality',
                        'category', 'packaging_method_id', 'package_size', 'default_packaging', 'min_quantity_value', 'min_quantity_unit',
                        'unit_price', 'currency', 'valid_until', 'status', 'image_path', 'quantity_available', 'is_bio',
                    ]) + [
                        'prices' => $record->prices->isNotEmpty()
                            ? $record->prices->map(fn ($price): array => [
                                'min_quantity_value' => $price->min_quantity_value,
                                'unit_price' => $price->unit_price,
                            ])->all()
                            : [[
                                'min_quantity_value' => $record->min_quantity_value,
                                'unit_price' => $record->unit_price,
                            ]],
                    ])
                    ->schema(fn (Schema $schema): Schema => $schema->components(static::productFormComponents(includePriceBreaks: true)))
                    ->action(function (SupplierProduct $record, array $data): void {
                        $prices = $data['prices'] ?? [];
                        unset($data['prices']);

                        $record->update($data);
                        static::replacePriceBreaks($record, $prices);
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierProducts::route('/'),
        ];
    }
}
