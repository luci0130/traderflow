<?php

namespace App\Modules\SalesOrders\Filament\Resources\SalesOrders;

use App\Filament\Concerns\ScopesToActiveTenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\RelationManagers\ItemsRelationManager;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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

class SalesOrderResource extends Resource
{
    use ScopesToActiveTenant;

    protected static ?string $model = SalesOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getModelLabel(): string
    {
        return __('Sales order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
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
                        Select::make('customer_offer_id')
                            ->label('Customer offer')
                            ->options(fn (): array => CustomerOffer::query()
                                ->when(! static::canSeeAllTenants(), fn ($query) => $query->where('tenant_id', static::getActiveTenantId()))
                                ->orderBy('offer_number')
                                ->pluck('offer_number', 'id')
                                ->all())
                            ->searchable(),
                        TextInput::make('order_number')->maxLength(255),
                        DatePicker::make('order_date'),
                        DatePicker::make('delivery_date'),
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
                                'confirmed' => 'Confirmed',
                                'in_preparation' => 'In preparation',
                                'delivered' => 'Delivered',
                                'invoiced' => 'Invoiced',
                                'paid' => 'Paid',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('draft')
                            ->required(),
                        TextInput::make('subtotal')->numeric()->default(0),
                        TextInput::make('tax_total')->numeric()->default(0),
                        TextInput::make('total')->numeric()->default(0),
                        Placeholder::make('profit_total')
                            ->label(__('Profit'))
                            ->content(fn (?SalesOrder $record): string => $record
                                ? number_format(
                                    $record->items->sum(fn ($i) => (float) $i->margin_value * (float) $i->quantity),
                                    2
                                ).' '.($record->currency ?? 'EUR')
                                : '-'),
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
                TextColumn::make('order_number')->searchable()->sortable(),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('customerOffer.offer_number')->searchable()->toggleable(),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('delivery_date')->date()->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state): array => StatusColors::badge($state))->searchable()->sortable(),
                TextColumn::make('total')
                    ->money(fn (SalesOrder $record): string => $record->currency ?? 'EUR')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'confirmed' => 'Confirmed',
                        'in_preparation' => 'In preparation',
                        'delivered' => 'Delivered',
                        'invoiced' => 'Invoiced',
                        'paid' => 'Paid',
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
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesOrders::route('/'),
            'create' => CreateSalesOrder::route('/create'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
