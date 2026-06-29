<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers;

use App\Modules\MarketComparison\Models\SupplierReview;
use App\Modules\Producers\Models\ProducerOrder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Support\StatusColors;
use App\Support\Tenancy\ActiveTenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'producerOrders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Orders');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->producerOrders()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Order'))
                    ->schema([
                        TextInput::make('order_number')
                            ->label(__('Order number'))
                            ->maxLength(255),
                        DatePicker::make('order_date')
                            ->label(__('Order date'))
                            ->native(false)
                            ->default(today()),
                        DatePicker::make('expected_delivery_date')
                            ->label(__('Expected delivery'))
                            ->native(false),
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(static::translatedStatuses())
                            ->default('draft')
                            ->required(),
                        Select::make('currency')
                            ->label(__('Currency'))
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(__('Items'))
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->label('')
                            ->columns(12)
                            ->reorderableWithButtons()
                            ->addActionLabel(__('Add product'))
                            ->live()
                            ->schema([
                                Select::make('supplier_product_id')
                                    ->label(__('Product'))
                                    ->options(fn (): array => $this->producerProductOptions())
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(5)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        if ($state === null) {
                                            return;
                                        }

                                        $product = SupplierProduct::find($state);
                                        if ($product === null) {
                                            return;
                                        }

                                        $set('product_name', $product->name);
                                        $set('unit', $product->min_quantity_unit);
                                        $set('currency', $product->currency);

                                        if (blank($get('unit_price'))) {
                                            $set('unit_price', (float) $product->unit_price);
                                        }
                                        if (blank($get('quantity'))) {
                                            $set('quantity', (float) ($product->min_quantity_value ?? 1));
                                        }
                                    }),
                                TextInput::make('quantity')
                                    ->label(__('Qty'))
                                    ->numeric()
                                    ->step('0.0001')
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2)
                                    ->live(onBlur: true),
                                TextInput::make('unit')
                                    ->label(__('Unit'))
                                    ->maxLength(16)
                                    ->columnSpan(1),
                                TextInput::make('unit_price')
                                    ->label(__('Unit price'))
                                    ->numeric()
                                    ->step('0.0001')
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2)
                                    ->live(onBlur: true),
                                TextInput::make('currency')
                                    ->label(__('Cur'))
                                    ->maxLength(3)
                                    ->columnSpan(1)
                                    ->default('EUR'),
                                TextInput::make('line_total')
                                    ->label(__('Total'))
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->columnSpan(1)
                                    ->formatStateUsing(fn (Get $get): string => number_format(
                                        (float) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                                        2,
                                    )),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                if (! isset($data['product_name']) && isset($data['supplier_product_id'])) {
                                    $data['product_name'] = SupplierProduct::find($data['supplier_product_id'])?->name;
                                }

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                if (! isset($data['product_name']) && isset($data['supplier_product_id'])) {
                                    $data['product_name'] = SupplierProduct::find($data['supplier_product_id'])?->name;
                                }

                                return $data;
                            }),
                    ]),

                Section::make(__('Notes'))
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')->label('')->rows(3),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tenant_id', app(ActiveTenant::class)->id()))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->label(__('Order #'))->searchable()->sortable(),
                TextColumn::make('order_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (ProducerOrder $record): string => ' '.$record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (?string $state): array => StatusColors::badge($state))
                    ->formatStateUsing(fn (string $state): string => static::translatedStatuses()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('expected_delivery_date')->label(__('Delivery'))->date()->toggleable(),
                TextColumn::make('creator.name')->label(__('Created by'))->toggleable()->placeholder('—'),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(static::translatedStatuses()),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('to')->label(__('To')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('order_date', '>=', $d))
                            ->when($data['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('order_date', '<=', $d));
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Create order'))
                    ->slideOver()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = app(ActiveTenant::class)->id();
                        $data['created_by'] = auth()->id();

                        return $data;
                    })
                    ->after(function (ProducerOrder $record): void {
                        $record->recalculateTotal();
                    }),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                EditAction::make()->slideOver()->after(fn (ProducerOrder $record) => $record->recalculateTotal()),
                Action::make('review')
                    ->label(fn (ProducerOrder $record): string => $record->review === null ? __('Review') : __('Edit review'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->fillForm(fn (ProducerOrder $record): array => [
                        'rating' => $record->review?->rating,
                        'comment' => $record->review?->comment,
                    ])
                    ->schema([
                        Select::make('rating')
                            ->label(__('Rating'))
                            ->options([
                                5 => '★★★★★',
                                4 => '★★★★',
                                3 => '★★★',
                                2 => '★★',
                                1 => '★',
                            ])
                            ->required(),
                        Textarea::make('comment')
                            ->label(__('Comment'))
                            ->rows(3),
                    ])
                    ->action(function (ProducerOrder $record, array $data): void {
                        SupplierReview::updateOrCreate(
                            ['producer_order_id' => $record->getKey()],
                            [
                                'supplier_id' => $record->producer_id,
                                'rating' => $data['rating'],
                                'comment' => $data['comment'] ?? null,
                                'reviewed_by' => auth()->id(),
                            ],
                        );
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function translatedStatuses(): array
    {
        return array_map(fn (string $label): string => __($label), ProducerOrder::STATUSES);
    }

    /**
     * @return array<int, string>
     */
    private function producerProductOptions(): array
    {
        return SupplierProduct::query()
            ->where('producer_id', $this->getOwnerRecord()->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
