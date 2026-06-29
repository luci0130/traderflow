<?php

namespace App\Modules\SupplierOrders\Filament\Resources\SupplierOrders;

use App\Filament\Concerns\ScopesToActiveTenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages\CreateSupplierOrder;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages\EditSupplierOrder;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages\ListSupplierOrders;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\RelationManagers\ItemsRelationManager;
use App\Modules\SupplierOrders\Models\SupplierOrder;
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

class SupplierOrderResource extends Resource
{
    use ScopesToActiveTenant;

    protected static ?string $model = SupplierOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'order_number';

    /**
     * @var array<string, string>
     */
    private const STATUSES = [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'in_preparation' => 'In preparation',
        'received' => 'Received',
        'invoiced' => 'Invoiced',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
    ];

    public static function getModelLabel(): string
    {
        return __('Supplier order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Purchasing');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->schema([
                        static::tenantSelect(),
                        Select::make('supplier_id')
                            ->label(__('Supplier'))
                            ->options(fn (): array => Supplier::query()
                                ->visibleToTenant(static::canSeeAllTenants() ? null : static::getActiveTenantId())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->searchable(),
                        Select::make('supplier_offer_id')
                            ->label(__('Supplier offer'))
                            ->options(fn (): array => SupplierOffer::query()
                                ->when(! static::canSeeAllTenants(), fn ($query) => $query->where('tenant_id', static::getActiveTenantId()))
                                ->with('supplier')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (SupplierOffer $offer): array => [
                                    $offer->id => trim(($offer->offer_number ?: __('Supplier offer').' #'.$offer->id)
                                        .($offer->supplier?->name ? ' — '.$offer->supplier->name : '')),
                                ])
                                ->all())
                            ->searchable(),
                        Select::make('customer_offer_id')
                            ->label(__('Customer offer'))
                            ->options(fn (): array => CustomerOffer::query()
                                ->when(! static::canSeeAllTenants(), fn ($query) => $query->where('tenant_id', static::getActiveTenantId()))
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (CustomerOffer $offer): array => [
                                    $offer->id => $offer->offer_number ?: __('Customer offer').' #'.$offer->id,
                                ])
                                ->all())
                            ->searchable(),
                        TextInput::make('order_number')->maxLength(255),
                        DatePicker::make('order_date'),
                        DatePicker::make('expected_date'),
                        Select::make('currency')
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                        Select::make('status')
                            ->options(self::STATUSES)
                            ->default('draft')
                            ->required(),
                        TextInput::make('subtotal')->numeric()->default(0),
                        TextInput::make('tax_total')->numeric()->default(0),
                        TextInput::make('total')->numeric()->default(0),
                        Textarea::make('notes')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('tenant.name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order_number')->label(__('Order number'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
                TextColumn::make('supplierOffer.offer_number')->label(__('Supplier offer'))->toggleable()->placeholder('—'),
                TextColumn::make('customerOffer.offer_number')->label(__('Customer offer'))->toggleable()->placeholder('—'),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('expected_date')->date()->sortable()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
                TextColumn::make('total')
                    ->money(fn (SupplierOrder $record): string => $record->currency ?? 'EUR')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
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
            'index' => ListSupplierOrders::route('/'),
            'create' => CreateSupplierOrder::route('/create'),
            'edit' => EditSupplierOrder::route('/{record}/edit'),
        ];
    }
}
