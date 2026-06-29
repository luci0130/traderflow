<?php

namespace App\Modules\SupplierOffers\Filament\Resources\SupplierOffers;

use App\Filament\Concerns\ScopesToActiveTenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages\CreateSupplierOffer;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages\EditSupplierOffer;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages\ListSupplierOffers;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\RelationManagers\ItemsRelationManager;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
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

class SupplierOfferResource extends Resource
{
    use ScopesToActiveTenant;

    protected static ?string $model = SupplierOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'offer_number';

    public static function getModelLabel(): string
    {
        return __('Supplier offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier offers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Purchasing');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Offer')
                    ->schema([
                        static::tenantSelect(),
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(fn (): array => Supplier::query()
                                ->visibleToTenant(static::canSeeAllTenants() ? null : static::getActiveTenantId())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->searchable(),
                        TextInput::make('offer_number')
                            ->maxLength(255),
                        Select::make('customer_offer_id')
                            ->label(__('Customer offer'))
                            ->options(fn (): array => CustomerOffer::query()
                                ->with('customer')
                                ->latest('id')
                                ->get()
                                ->mapWithKeys(fn (CustomerOffer $offer): array => [
                                    $offer->id => static::customerOfferLabel($offer),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            // Once a supplier offer is assigned to a customer offer the link is
                            // locked: the select is disabled (and not dehydrated, so the value is
                            // preserved). Unassigned offers stay an editable customer-offer picker.
                            ->disabled(fn (?SupplierOffer $record): bool => filled($record?->customer_offer_id))
                            ->helperText(fn (?SupplierOffer $record): ?string => filled($record?->customer_offer_id)
                                ? __('Already assigned to a customer offer; the link cannot be changed.')
                                : null),
                        DatePicker::make('received_at'),
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
                                'received' => 'Received',
                                'processed' => 'Processed',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                                'expired' => 'Expired',
                            ])
                            ->default('draft')
                            ->required(),
                        Select::make('source_type')
                            ->options([
                                'manual' => 'Manual',
                                'email' => 'Email',
                                'pdf' => 'PDF',
                                'image' => 'Image',
                                'excel' => 'Excel',
                                'api' => 'API',
                            ])
                            ->default('manual')
                            ->required(),
                        Textarea::make('notes')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Human-readable label for a customer offer option: its offer number (or a
     * fallback id) followed by the customer name.
     */
    protected static function customerOfferLabel(CustomerOffer $offer): string
    {
        $number = filled($offer->offer_number) ? $offer->offer_number : '#'.$offer->getKey();
        $customer = $offer->customer?->name;

        return $customer !== null ? "{$number} — {$customer}" : $number;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('offer_number')
            ->columns([
                TextColumn::make('tenant.name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('offer_number')->searchable()->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('received_at')->date()->sortable(),
                TextColumn::make('valid_until')->date()->sortable(),
                TextColumn::make('currency')->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
                TextColumn::make('source_type')->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'received' => 'Received',
                        'processed' => 'Processed',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
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
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierOffers::route('/'),
            'create' => CreateSupplierOffer::route('/create'),
            'edit' => EditSupplierOffer::route('/{record}/edit'),
        ];
    }
}
