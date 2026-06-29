<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers;

use App\Modules\Supermarkets\Models\SupermarketProduct;
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

class SupermarketProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supermarketProducts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Supermarket products');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->supermarketProducts()->count();

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
                TextColumn::make('brand')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('category')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('barcode')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('package_size')
                    ->label(__('Package'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (SupermarketProduct $record): string => $record->package_unit ? ' '.$record->package_unit : '')
                    ->placeholder('-'),
                TextColumn::make('prices_count')
                    ->label(__('Prices'))
                    ->counts('prices')
                    ->badge(),
            ])
            ->headerActions([
                Action::make('map')
                    ->label(__('Map supermarket products'))
                    ->icon(Heroicon::OutlinedLink)
                    ->modalHeading(__('Map supermarket products to this canonical product'))
                    ->modalSubmitActionLabel(__('Map'))
                    ->schema([
                        Select::make('records')
                            ->label(__('Supermarket products'))
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
     * Supermarket products selectable for mapping: everything not already mapped
     * to THIS canonical product, with the closest name matches suggested first
     * and a hint when a product is currently grouped elsewhere.
     *
     * @return array<int, string>
     */
    protected function mappableOptions(): array
    {
        $owner = $this->getOwnerRecord();

        return SupermarketProduct::query()
            ->with('canonicalProducts:id,name')
            ->whereDoesntHave('canonicalProducts', fn (Builder $query): Builder => $query->whereKey($owner->getKey()))
            ->orderByRaw('(name like ?) desc', ['%'.$owner->name.'%'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (SupermarketProduct $product): array {
                $current = $product->canonicalProducts->first();
                $packaging = $product->package_size
                    ? trim(rtrim(rtrim(number_format((float) $product->package_size, 2, '.', ''), '0'), '.').' '.$product->package_unit)
                    : ($product->package_unit ?: null);

                $label = $product->name
                    .($product->brand ? ' · '.$product->brand : '')
                    .($packaging ? ' · '.$packaging : '')
                    .($current ? '  — '.__('mapped: :name', ['name' => $current->name]) : '');

                return [$product->getKey() => $label];
            })
            ->all();
    }

    /**
     * Map the given supermarket products to this canonical product, moving each
     * one out of any other canonical first (one product = one canonical).
     *
     * @param  array<int, int|string>  $ids
     */
    protected function mapRecords(array $ids): null
    {
        $ids = array_map('intval', $ids);

        SupermarketProduct::query()
            ->whereKey($ids)
            ->get()
            ->each(fn (SupermarketProduct $product) => $product->canonicalProducts()->detach());

        $this->getOwnerRecord()->supermarketProducts()->syncWithoutDetaching($ids);

        return null;
    }
}
