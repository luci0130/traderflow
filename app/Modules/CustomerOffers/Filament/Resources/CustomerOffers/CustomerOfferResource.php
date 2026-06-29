<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers;

use App\Filament\Concerns\ScopesToActiveTenant;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\CreateCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\ListCustomerOffers;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\SupplierOffersRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

class CustomerOfferResource extends Resource
{
    use ScopesToActiveTenant;

    protected static ?string $model = CustomerOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'offer_number';

    public static function getModelLabel(): string
    {
        return __('Customer offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customer offers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Offer')
                    ->schema([
                        static::tenantSelect(),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->options(fn (): array => Customer::query()
                                ->visibleToTenant(static::canSeeAllTenants() ? null : static::getActiveTenantId())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->searchable(),
                        TextInput::make('offer_number')->maxLength(255),
                        DatePicker::make('offer_date'),
                        DatePicker::make('valid_until'),
                        Select::make('currency')
                            ->options([
                                'EUR' => 'EUR',
                                'RON' => 'RON',
                                'USD' => 'USD',
                                'GBP' => 'GBP',
                            ])
                            ->default('EUR')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'sent' => 'Sent',
                                'accepted' => 'Accepted',
                                'rejected' => 'Rejected',
                                'expired' => 'Expired',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('draft')
                            ->required(),
                        TextInput::make('subtotal')->numeric()->default(0),
                        TextInput::make('tax_total')->numeric()->default(0),
                        TextInput::make('total')->numeric()->default(0),
                        // Email subject/body are set in the "Send Offer Email" modal, not here.
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('offer_number')
            ->columns([
                TextColumn::make('tenant.name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('offer_number')->searchable()->sortable(),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('offer_date')->date()->sortable(),
                TextColumn::make('valid_until')->date()->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
                TextColumn::make('total')
                    ->money(fn (CustomerOffer $record): string => $record->currency ?? 'EUR')
                    ->sortable(),
                TextColumn::make('sent_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),
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

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            SupplierOffersRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerOffers::route('/'),
            'create' => CreateCustomerOffer::route('/create'),
            'edit' => EditCustomerOffer::route('/{record}/edit'),
        ];
    }
}
