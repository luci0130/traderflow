<?php

namespace App\Modules\Customers\Filament\RelationManagers;

use App\Modules\SalesOrders\Models\SalesOrder;
use App\Support\StatusColors;
use App\Support\Tenancy\ActiveTenant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'salesOrders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Orders');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->salesOrders()
            ->where('tenant_id', app(ActiveTenant::class)->id())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return array<string, string>
     */
    protected static function statuses(): array
    {
        return [
            'draft' => __('Draft'),
            'confirmed' => __('Confirmed'),
            'in_preparation' => __('In preparation'),
            'delivered' => __('Delivered'),
            'invoiced' => __('Invoiced'),
            'paid' => __('Paid'),
            'cancelled' => __('Cancelled'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Order'))
                    ->schema([
                        TextInput::make('order_number')->label(__('Order number'))->maxLength(255),
                        DatePicker::make('order_date')->label(__('Order date'))->default(today()),
                        DatePicker::make('delivery_date')->label(__('Delivery date')),
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(static::statuses())
                            ->default('draft')
                            ->required(),
                        Select::make('currency')
                            ->label(__('Currency'))
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                        TextInput::make('subtotal')->label(__('Subtotal'))->numeric()->default(0),
                        TextInput::make('tax_total')->label(__('Tax total'))->numeric()->default(0),
                        TextInput::make('total')->label(__('Total'))->numeric()->default(0),
                        Textarea::make('notes')->label(__('Notes'))->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tenant_id', app(ActiveTenant::class)->id()))
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('order_number')->label(__('Order #'))->searchable()->sortable(),
                TextColumn::make('order_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('delivery_date')->label(__('Delivery'))->date()->sortable()->toggleable(),
                TextColumn::make('items_count')->label(__('Items'))->counts('items')->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (?string $state): array => StatusColors::badge($state))->sortable(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SalesOrder $record): string => ' '.$record->currency)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(static::statuses()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Create order'))
                    ->slideOver()
                    ->mutateDataUsing(function (array $data): array {
                        $data['tenant_id'] = app(ActiveTenant::class)->id();
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
