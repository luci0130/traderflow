<?php

namespace App\Modules\Customers\Filament\Resources\Customers;

use App\Modules\Customers\Filament\RelationManagers\ContactsRelationManager;
use App\Modules\Customers\Filament\RelationManagers\LocationsRelationManager;
use App\Modules\Customers\Filament\RelationManagers\OrdersRelationManager;
use App\Modules\Customers\Filament\RelationManagers\ProductsRelationManager;
use App\Modules\Customers\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\ListCustomers;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Entities';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Entities');
    }

    /**
     * Show tenant-scoped customers together with the globally shared
     * supermarkets (which carry no tenant_id).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleToTenant(null);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer')
                    ->schema([
                        Hidden::make('tenant_id')
                            ->default(null)
                            ->dehydrated(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $set, Get $get): void {
                                if (blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('legal_name')->maxLength(255),
                        TextInput::make('vat_number')->maxLength(255),
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
                        Toggle::make('is_active')
                            ->default(true),
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('supermarkets')
                            ->maxSize(2048)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Address and terms')
                    ->schema([
                        TextInput::make('country')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        Textarea::make('address')->columnSpanFull(),
                        Textarea::make('payment_terms')->columnSpanFull(),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('logo')->disk('public')->square()->size(40)->toggleable(),
                TextColumn::make('tenant.name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('legal_name')->searchable()->toggleable(),
                TextColumn::make('vat_number')->searchable()->toggleable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->searchable()->toggleable(),
                TextColumn::make('city')->searchable()->toggleable(),
                TextColumn::make('country')->searchable()->toggleable(),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
                TextColumn::make('prices_count')->label('Prices')->counts('prices')->sortable()->toggleable(isToggledHiddenByDefault: true),
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
     * Distinct, non-empty cities among the customers visible to the current scope.
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
            ContactsRelationManager::class,
            LocationsRelationManager::class,
            ProductsRelationManager::class,
            OrdersRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
