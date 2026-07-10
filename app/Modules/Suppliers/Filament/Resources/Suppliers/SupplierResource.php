<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers;

use App\Models\Tenant;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\ContactsRelationManager;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\OrdersRelationManager;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\ProductsRelationManager;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\ReviewsRelationManager;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\UsersRelationManager;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Entities';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Suppliers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Entities');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel?->hasTenancy()) {
            $query->withoutGlobalScope($panel->getTenancyScopeName());
        }

        return $query
            ->visibleToTenant(null)
            ->withCount([
                'supplierProducts',
                'supplierProducts as valid_supplier_products_count' => fn (Builder $query): Builder => $query
                    ->where('status', 'active')
                    ->whereDate('valid_until', '>=', today()),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Supplier')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Select::make('tenant_id')
                            ->label(__('Tenant scope'))
                            ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder(__('Global'))
                            ->default(null)
                            ->disabled(fn (): bool => ! (auth()->user()?->isSuperAdmin() ?? false))
                            ->dehydrated()
                            ->searchable()
                            ->nullable(),
                        Select::make('management_mode')
                            ->label(__('Management mode'))
                            ->options([
                                Supplier::MANAGEMENT_MODE_OPERATOR => __('Operator managed'),
                                Supplier::MANAGEMENT_MODE_SELF => __('Self managed'),
                            ])
                            ->default(Supplier::MANAGEMENT_MODE_OPERATOR)
                            ->required(),
                        Toggle::make('is_producer')
                            ->label(__('Producer capabilities'))
                            ->default(false),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('legal_name')->maxLength(255),
                        TextInput::make('vat_number')->maxLength(255),
                        TextInput::make('registration_number')->maxLength(255),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->maxLength(255),
                        TextInput::make('contact_person')->maxLength(255),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Address and terms')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('country')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('postal_code')->maxLength(255),
                        Textarea::make('address')->columnSpanFull(),
                        Textarea::make('payment_terms')->columnSpanFull(),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Banking and invoicing'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('iban')->maxLength(255),
                        TextInput::make('bank_name')->maxLength(255),
                        TextInput::make('bank_swift')->maxLength(255),
                        Select::make('default_currency')
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                        TextInput::make('invoice_prefix')->maxLength(255),
                        TextInput::make('invoice_starting_number')
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        Textarea::make('invoice_notes')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Sourcing costs'))
                    ->description(__('Default costs applied to all of this supplier\'s products. Each product can override them individually.'))
                    ->collapsed()
                    ->relationship('costDefault')
                    ->schema([
                        TextInput::make('packaging_cost')
                            ->label(__('Packaging cost'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('transport_cost')
                            ->label(__('Transport cost'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('commission')
                            ->label(__('Commission'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('profit_margin')
                            ->label(__('Profit margin'))
                            ->numeric()
                            ->minValue(0),
                        Select::make('cost_basis')
                            ->label(__('Margin basis'))
                            ->options([
                                SupplierCostDefault::COST_BASIS_PER_UNIT => __('Per unit'),
                                SupplierCostDefault::COST_BASIS_PERCENT => __('Percent of landed cost'),
                            ])
                            ->default(SupplierCostDefault::COST_BASIS_PER_UNIT)
                            ->required(),
                        Select::make('currency')
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('tenant.name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tenant_id')
                    ->label(__('Scope'))
                    ->formatStateUsing(fn ($state): string => $state === null ? __('Global') : __('Tenant'))
                    ->badge(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('legal_name')->searchable()->toggleable(),
                TextColumn::make('vat_number')->searchable()->toggleable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('city')->searchable()->toggleable(),
                TextColumn::make('country')->searchable()->toggleable(),
                TextColumn::make('management_mode')->badge()->toggleable(),
                TextColumn::make('is_producer')->badge()->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))->toggleable(),
                TextColumn::make('users_count')
                    ->label(__('Users'))
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('supplier_products_count')
                    ->label(__('Products'))
                    ->sortable(),
                TextColumn::make('offers_status')
                    ->label(__('Offers'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Supplier::OFFERS_STATUS_VALID => __('Active'),
                        Supplier::OFFERS_STATUS_MIXED => __('Mixed'),
                        Supplier::OFFERS_STATUS_NONE => __('No products'),
                        Supplier::OFFERS_STATUS_EXPIRED => __('Expired'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Supplier::OFFERS_STATUS_VALID => 'success',
                        Supplier::OFFERS_STATUS_MIXED => 'warning',
                        Supplier::OFFERS_STATUS_NONE => 'danger',
                        Supplier::OFFERS_STATUS_EXPIRED => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
                SelectFilter::make('city')
                    ->label(__('City'))
                    ->options(fn (): array => static::cityFilterOptions())
                    ->searchable(),
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

    /**
     * Distinct, non-empty cities among the suppliers visible to the current scope.
     *
     * @return array<string, string>
     */
    protected static function cityFilterOptions(): array
    {
        return static::getEloquentQuery()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city', 'city')
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
            ContactsRelationManager::class,
            UsersRelationManager::class,
            OrdersRelationManager::class,
            ReviewsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
