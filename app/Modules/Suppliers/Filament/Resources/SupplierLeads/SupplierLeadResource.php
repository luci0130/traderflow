<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierLeads;

use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketPricesRelationManager;
use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketProductsRelationManager;
use App\Modules\Suppliers\Actions\ConvertSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\CreateSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\EditSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\ListSupplierLeads;
use App\Modules\Suppliers\Models\SupplierLead;
use App\Support\CountryFilter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierLeadResource extends Resource
{
    protected static ?string $model = SupplierLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Entities';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Supplier lead');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier leads');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Entities');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->whereNull('converted_supplier_id')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Supplier lead'))
                    ->description(__('Contact details for a potential supplier. Once someone contacts them, convert the lead into a real supplier.'))
                    ->columns(2)
                    ->schema([
                        ...static::contactFields(),
                        Select::make('supermarketProducts')
                            ->label(__('Supermarket products'))
                            ->relationship('supermarketProducts', 'name')
                            ->saveRelationshipsUsing(fn (SupplierLead $record, array $state): array => $record->supermarketProducts()->sync(array_unique([
                                ...$state,
                                ...$record->supermarketPrices()->pluck('supermarket_product_id')->all(),
                            ])))
                            ->helperText(__('Products with recorded prices remain associated.'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<Component> */
    public static function contactFields(): array
    {
        return [
            TextInput::make('name')->label(__('Company name'))->required()->maxLength(255),
            TextInput::make('country')->label(__('Country'))->maxLength(255),
            Textarea::make('address')->label(__('Address'))->columnSpanFull(),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(255),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
            TextInput::make('website')->label(__('Website'))->url()->maxLength(255),
            Textarea::make('notes')->label(__('Notes about supplier'))->columnSpanFull(),
        ];
    }

    public static function getRelations(): array
    {
        return [SupermarketProductsRelationManager::class, SupermarketPricesRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country')
                    ->label(__('Country'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('website')
                    ->label(__('Website'))
                    ->url(fn (SupplierLead $record): ?string => $record->website)
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('supermarketProducts.name')
                    ->label(__('Product'))
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label(__('Created by'))
                    ->toggleable(),
                IconColumn::make('converted_supplier_id')
                    ->label(__('Converted'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Added on'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                CountryFilter::make(),
                TernaryFilter::make('converted')
                    ->label(__('Converted'))
                    ->placeholder(__('All leads'))
                    ->trueLabel(__('Converted'))
                    ->falseLabel(__('Not converted'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('converted_supplier_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('converted_supplier_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('convertToSupplier')
                    ->label(__('Convert to supplier'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('success')
                    ->visible(fn (SupplierLead $record): bool => ! $record->isConverted() && (auth()->user()?->can('update', $record) ?? false) && (auth()->user()?->can('Create:Supplier') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('This creates a supplier from the lead\'s contact details and marks the lead as converted.'))
                    ->action(function (SupplierLead $record): void {
                        app(ConvertSupplierLead::class)->handle($record);

                        Notification::make()
                            ->title(__('Lead converted to supplier'))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
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
            'index' => ListSupplierLeads::route('/'),
            'create' => CreateSupplierLead::route('/create'),
            'edit' => EditSupplierLead::route('/{record}/edit'),
        ];
    }
}
