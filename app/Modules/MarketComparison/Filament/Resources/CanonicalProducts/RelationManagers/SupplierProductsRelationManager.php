<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers;

use App\Modules\Producers\Models\SupplierProduct;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Supplier products');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->supplierProducts()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('variety')
                    ->label(__('Variety'))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('default_packaging')
                    ->label(__('Packaging'))
                    ->placeholder('-'),
                TextColumn::make('unit_price')
                    ->label(__('Price'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SupplierProduct $record): string => ' '.($record->currency ?? ''))
                    ->sortable(),
                TextColumn::make('quantity_available')
                    ->label(__('Available'))
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-'),
                TextColumn::make('valid_until')
                    ->label(__('Valid until'))
                    ->date()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('map')
                    ->label(__('Map supplier products'))
                    ->icon(Heroicon::OutlinedLink)
                    ->modalHeading(__('Map supplier products to this canonical product'))
                    ->modalSubmitActionLabel(__('Map'))
                    ->schema([
                        Select::make('records')
                            ->label(__('Supplier products'))
                            ->multiple()
                            ->options(fn (): array => $this->mappableOptions())
                            ->searchable()
                            ->required()
                            ->helperText(__('You can select several at once. Products already grouped elsewhere will be moved here.')),
                    ])
                    ->action(fn (array $data): null => $this->mapRecords($data['records'] ?? [])),
            ])
            ->recordActions([
                DetachAction::make()->label(__('Unmap')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label(__('Unmap selected')),
                ]),
            ]);
    }

    /**
     * Supplier products selectable for mapping: everything not already mapped to
     * THIS canonical product, closest name matches first, with a hint when a
     * product is currently grouped elsewhere.
     *
     * @return array<int, string>
     */
    protected function mappableOptions(): array
    {
        $owner = $this->getOwnerRecord();

        return SupplierProduct::query()
            ->with(['supplier:id,name', 'canonicalProducts:id,name'])
            ->whereDoesntHave('canonicalProducts', fn (Builder $query): Builder => $query->whereKey($owner->getKey()))
            ->orderByRaw('(name like ?) desc', ['%'.$owner->name.'%'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (SupplierProduct $product): array {
                $current = $product->canonicalProducts->first();

                $label = $product->name
                    .($product->supplier?->name ? ' · '.$product->supplier->name : '')
                    .($product->default_packaging ? ' · '.$product->default_packaging : '')
                    .($current ? '  — '.__('mapped: :name', ['name' => $current->name]) : '');

                return [$product->getKey() => $label];
            })
            ->all();
    }

    /**
     * Map the given supplier products to this canonical product, moving each one
     * out of any other canonical first (one product = one canonical).
     *
     * @param  array<int, int|string>  $ids
     */
    protected function mapRecords(array $ids): null
    {
        $ids = array_map('intval', $ids);

        SupplierProduct::query()
            ->whereKey($ids)
            ->get()
            ->each(fn (SupplierProduct $product) => $product->canonicalProducts()->detach());

        $this->getOwnerRecord()->supplierProducts()->syncWithoutDetaching($ids);

        return null;
    }
}
