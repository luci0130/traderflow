<?php

namespace App\Modules\MarketComparison\Filament\Pages;

use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Data\MarketComparisonRow;
use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Filament\Concerns\CreatesCustomerOfferFromSelection;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Modules\MarketComparison\Services\MarketComparisonRowAssembler;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\Countries;
use App\Support\Tenancy\ActiveTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use UnitEnum;

class MarketComparison extends Page implements HasTable
{
    use CreatesCustomerOfferFromSelection;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'market-comparison';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupermarketPrice') ?? false;
    }

    protected Width|string|null $maxContentWidth = 'full';

    /**
     * Assembled rows memoized per request, keyed by canonical product id.
     *
     * @var array<int, MarketComparisonRow>
     */
    protected array $rowCache = [];

    /**
     * Supplier candidates memoized per request, keyed by canonical product id,
     * so the several columns that need them don't each re-run the query.
     *
     * @var array<int, Collection<int, SupplierPriceCandidate>>
     */
    protected array $supplierCandidatesCache = [];

    /**
     * Default (margin-descending) product ordering, cached across Livewire
     * requests. It doesn't change when the user picks suppliers, so recomputing
     * it (which assembles every product) on each interaction is wasteful; it is
     * recomputed only after a full page load.
     *
     * @var list<int>
     */
    public array $marginOrderCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    public array $expandedCanonicalIds = [];

    /**
     * Prioritized suppliers per canonical product, set from the breakdown's
     * "Supplier prices" rows by clicking them in order. The list order is the
     * contact priority (index 0 = priority #1 = buy source for the offer).
     * Keyed canonicalProductId => ordered list of supplierProductIds.
     *
     * @var array<int, list<int>>
     */
    public array $selectedSupplierProductIds = [];

    /**
     * Maximum suppliers that can be prioritized per product. Set to null to
     * lift the cap.
     */
    public const MAX_SUPPLIERS_PER_PRODUCT = 5;

    public function getTitle(): string
    {
        return __('Market comparison');
    }

    public static function getNavigationLabel(): string
    {
        return __('Market comparison');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Analytics');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getComparisonQuery())
            ->recordTitleAttribute('name')
            ->columns([
                Grid::make(['default' => 1, 'lg' => 17])
                    ->schema([
                        View::make('filament.pages.market-comparison.partials.product-select')
                            ->columnSpan(['lg' => 1]),
                        TextColumn::make('name')
                            ->label(__('Product'))
                            ->searchable()
                            ->sortable()
                            ->columnSpan(['lg' => 3]),
                        TextColumn::make('category.name')
                            ->label(__('Category'))
                            ->placeholder('-')
                            ->sortable()
                            ->columnSpan(['lg' => 2]),
                        TextColumn::make('packaging_variant')
                            ->label(__('Packaging'))
                            ->placeholder('-')
                            ->columnSpan(['lg' => 1]),
                        TextColumn::make('country_of_origin')
                            ->label(__('Country'))
                            ->formatStateUsing(fn (?string $state): ?string => Countries::label($state))
                            ->placeholder('-')
                            ->columnSpan(['lg' => 2]),
                        TextColumn::make('best_supplier')
                            ->label(__('Best supplier (buy)'))
                            ->state(fn (CanonicalProduct $record): string => $this->formatSupplier($record))
                            ->badge()
                            ->color('warning')
                            ->columnSpan(['lg' => 3]),
                        TextColumn::make('best_supermarket')
                            ->label(__('Best supermarket (sell)'))
                            ->state(fn (CanonicalProduct $record): string => $this->formatSupermarket($record))
                            ->badge()
                            ->color('success')
                            ->columnSpan(['lg' => 3]),
                        TextColumn::make('margin')
                            ->label(__('Profit margin'))
                            ->state(fn (CanonicalProduct $record): string => $this->formatMargin($record))
                            ->badge()
                            ->color(fn (CanonicalProduct $record): string => $this->marginColor($record))
                            ->columnSpan(['lg' => 2]),
                    ]),
                View::make('filament.pages.market-comparison.partials.breakdown'),
            ])
            ->recordActions([
                Action::make('toggleBreakdown')
                    ->label(__('Toggle prices'))
                    ->iconButton()
                    ->color('gray')
                    ->icon(fn (CanonicalProduct $record): Heroicon => array_key_exists($record->getKey(), $this->expandedCanonicalIds)
                        ? Heroicon::ChevronUp
                        : Heroicon::ChevronDown)
                    ->action(fn (CanonicalProduct $record) => $this->toggleBreakdown($record->getKey())),
            ])
            ->filters([
                SelectFilter::make('product_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => ProductCategory::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('supplier')
                    ->label(__('Supplier'))
                    ->options(fn (): array => $this->supplierOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $this->applySupplierFilter($query, $data['value'] ?? null)),
                SelectFilter::make('supermarket')
                    ->label(__('Supermarket'))
                    ->options(fn (): array => $this->supermarketOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $this->applySupermarketFilter($query, $data['value'] ?? null)),
                Filter::make('min_margin')
                    ->schema([
                        TextInput::make('value')
                            ->label(__('Minimum profit margin'))
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->applyMinMarginFilter($query, $data['value'] ?? null)),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createSupermarketOffer')
                ->label(__('Create supermarket offer'))
                ->badge(fn (): int => count($this->selectedSupplierProductIds))
                ->icon(Heroicon::OutlinedDocumentCurrencyEuro)
                ->disabled(fn (): bool => $this->selectedSupplierProductIds === [])
                ->modalHeading(__('Create supermarket offer from selected products'))
                ->modalWidth(Width::SevenExtraLarge)
                ->modalContentFooter(fn (): \Illuminate\Contracts\View\View => $this->offerSelectionFooter())
                ->schema($this->customerOfferFormSchema())
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    if ($this->createCustomerOfferFromSelection($this->selectedSupplierProductIds, $data)) {
                        $this->selectedSupplierProductIds = [];
                    }
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function offerCustomerOptions(): array
    {
        return $this->supermarketOptions();
    }

    /**
     * The canonical products currently ticked for the offer.
     *
     * @return EloquentCollection<int, CanonicalProduct>
     */
    protected function selectedCanonicalProducts(): EloquentCollection
    {
        return CanonicalProduct::query()
            ->whereKey(array_keys($this->selectedSupplierProductIds))
            ->get();
    }

    public function getComparisonQuery(): Builder
    {
        $query = CanonicalProduct::query()->with('category');

        // The profit-margin ordering is only the default; once the user sorts by a
        // column, defer to Filament's own ordering so name/category sorting works.
        if (filled($this->getTableSortColumn())) {
            return $query;
        }

        $orderedIds = $this->marginOrderedIds();

        if ($orderedIds !== []) {
            $caseExpression = 'CASE id';

            foreach ($orderedIds as $position => $id) {
                $caseExpression .= ' WHEN '.(int) $id.' THEN '.$position;
            }

            $caseExpression .= ' ELSE '.count($orderedIds).' END';

            $query->orderByRaw($caseExpression);
        }

        return $query;
    }

    public function toggleBreakdown(int $canonicalId): void
    {
        if (array_key_exists($canonicalId, $this->expandedCanonicalIds)) {
            unset($this->expandedCanonicalIds[$canonicalId]);

            return;
        }

        $canonicalProduct = CanonicalProduct::find($canonicalId);

        if ($canonicalProduct === null) {
            return;
        }

        $row = $this->assembleRow($canonicalProduct);

        $this->expandedCanonicalIds[$canonicalId] = [
            'suppliers' => $this->supplierCandidatesFor($canonicalProduct)
                ->map(fn ($candidate): array => [
                    'id' => $candidate->supplierProduct->getKey(),
                    'name' => $candidate->supplierName,
                    'city' => $candidate->supplierProduct->producer?->city,
                    'country' => Countries::normalize($candidate->supplierProduct->producer?->country),
                    'landed_cost' => $candidate->landedCost,
                    'unit_price' => $candidate->unitPrice,
                    'currency' => $candidate->currency,
                    'quantity' => $candidate->quantityAvailable,
                    'valid_until' => $candidate->validUntil,
                ])
                ->all(),
            'supermarkets' => app(SupermarketBestPriceQuery::class)
                ->candidatesFor($canonicalProduct)
                ->map(fn ($candidate): array => [
                    'name' => $candidate->supermarketName,
                    'gross_price' => $candidate->grossPrice,
                    'price_excl_vat' => $candidate->priceExclVat,
                    'currency' => $candidate->currency,
                    'observed_at' => $candidate->observedAt,
                    'is_promo' => $candidate->isPromo,
                ])
                ->all(),
        ];
    }

    /**
     * Toggle a supplier's priority for a canonical product. Clicking suppliers
     * in sequence appends them (priority 1, 2, 3… in click order); clicking an
     * already-picked supplier removes it and renumbers the rest. When the last
     * supplier is removed, the product drops out of the offer entirely.
     */
    public function toggleSupplierPriority(int $canonicalId, int $supplierProductId): void
    {
        $list = $this->selectedSupplierProductIds[$canonicalId] ?? [];
        $position = array_search($supplierProductId, $list, true);

        if ($position !== false) {
            array_splice($list, $position, 1);

            if ($list === []) {
                unset($this->selectedSupplierProductIds[$canonicalId]);

                return;
            }

            $this->selectedSupplierProductIds[$canonicalId] = $list;

            return;
        }

        if (self::MAX_SUPPLIERS_PER_PRODUCT !== null && count($list) >= self::MAX_SUPPLIERS_PER_PRODUCT) {
            Notification::make()
                ->title(__('At most :count suppliers per product', ['count' => self::MAX_SUPPLIERS_PER_PRODUCT]))
                ->warning()
                ->send();

            return;
        }

        $list[] = $supplierProductId;
        $this->selectedSupplierProductIds[$canonicalId] = $list;
    }

    /**
     * The 1-based contact priority of a supplier for a product, or null when it
     * is not selected. Drives the priority chip in the breakdown.
     */
    public function supplierPriority(int $canonicalId, int $supplierProductId): ?int
    {
        $list = $this->selectedSupplierProductIds[$canonicalId] ?? [];
        $position = array_search($supplierProductId, $list, true);

        return $position === false ? null : $position + 1;
    }

    /**
     * The ordered supplierProductIds prioritized for a product (priority order).
     *
     * @return list<int>
     */
    public function prioritizedSupplierProductIds(int $canonicalId): array
    {
        return $this->selectedSupplierProductIds[$canonicalId] ?? [];
    }

    /**
     * Include or drop a product from the offer as a whole, seeding priority #1
     * with the given (cheapest) supplier when empty. The main-row checkbox is a
     * read-only state indicator, so this is only used programmatically / by the
     * Best Prices page; the primary selection path is per-supplier priority.
     */
    public function toggleProductSelection(int $canonicalId, int $bestSupplierProductId): void
    {
        if (array_key_exists($canonicalId, $this->selectedSupplierProductIds)) {
            unset($this->selectedSupplierProductIds[$canonicalId]);

            return;
        }

        $this->selectedSupplierProductIds[$canonicalId] = [$bestSupplierProductId];
    }

    public function isProductSelected(int $canonicalId): bool
    {
        return array_key_exists($canonicalId, $this->selectedSupplierProductIds);
    }

    public function isSupplierSelected(int $canonicalId, int $supplierProductId): bool
    {
        return $this->supplierPriority($canonicalId, $supplierProductId) !== null;
    }

    public function bestSupplierProductId(CanonicalProduct $record): ?int
    {
        return $this->chosenSupplierCandidate($record)?->supplierProduct->getKey();
    }

    protected function assembleRow(CanonicalProduct $canonicalProduct): MarketComparisonRow
    {
        return $this->rowCache[$canonicalProduct->getKey()] ??= app(MarketComparisonRowAssembler::class)->assemble($canonicalProduct);
    }

    /**
     * Supplier candidates for a product, memoized per request.
     *
     * @return Collection<int, SupplierPriceCandidate>
     */
    protected function supplierCandidatesFor(CanonicalProduct $canonicalProduct): Collection
    {
        return $this->supplierCandidatesCache[$canonicalProduct->getKey()]
            ??= app(SupplierBestPriceQuery::class)->candidatesFor($canonicalProduct);
    }

    /**
     * The supplier candidate that will be used for a canonical product: the
     * hand-picked one when pinned, otherwise the cheapest by landed cost.
     */
    protected function chosenSupplierCandidate(CanonicalProduct $canonicalProduct): ?SupplierPriceCandidate
    {
        $candidates = $this->supplierCandidatesFor($canonicalProduct);
        $pinnedId = $this->selectedSupplierProductIds[$canonicalProduct->getKey()][0] ?? null;

        if ($pinnedId !== null) {
            $pinned = $candidates->first(
                fn (SupplierPriceCandidate $candidate): bool => $candidate->supplierProduct->getKey() === $pinnedId,
            );

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $candidates->first();
    }

    /**
     * One review line per selected canonical product, pairing it with the supplier
     * (cheapest landed cost) and supermarket price that will be used to build the
     * offer. Surfaced in the "Create supermarket offer" modal so the user can see
     * exactly which supplier each product is sourced from before confirming.
     *
     * @param  EloquentCollection<int, CanonicalProduct>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function offerSelectionLines(EloquentCollection $records): array
    {
        return $records->map(function (CanonicalProduct $record): array {
            $row = $this->assembleRow($record);
            $candidates = $this->prioritizedSupplierCandidates($record);
            $primary = $candidates[0] ?? null;
            $supermarket = $row->bestSupermarket;

            // Average of the chosen suppliers' product prices (unit price, not
            // landed cost) and the total quantity they can supply between them.
            $prices = array_map(fn (SupplierPriceCandidate $candidate): float => $candidate->unitPrice, $candidates);
            $availabilities = array_values(array_filter(
                array_map(fn (SupplierPriceCandidate $candidate): ?float => $candidate->quantityAvailable, $candidates),
                fn (?float $value): bool => $value !== null,
            ));

            return [
                'canonical_id' => $record->getKey(),
                'product' => $record->name,
                'packaging' => $record->packaging_variant,
                'country' => Countries::label($record->country_of_origin),
                'avg_price' => $prices !== [] ? array_sum($prices) / count($prices) : null,
                'supplier_currency' => $primary?->currency,
                // The editable quantity's ceiling: the suppliers' combined availability.
                'quantity_available' => $availabilities !== [] ? array_sum($availabilities) : null,
                'supermarket' => $supermarket?->supermarketName,
                'supermarket_price' => $supermarket?->grossPrice,
                'supermarket_currency' => $supermarket?->currency,
                'has_supplier' => $primary !== null,
                'suppliers' => array_map(fn (SupplierPriceCandidate $candidate, int $index): array => [
                    'priority' => $index + 1,
                    'name' => $candidate->supplierName,
                    'country' => Countries::label($candidate->countryOfOrigin),
                    'price' => $candidate->unitPrice,
                    'currency' => $candidate->currency,
                    'quantity_available' => $candidate->quantityAvailable,
                ], $candidates, array_keys($candidates)),
            ];
        })->all();
    }

    /**
     * The supplier candidates for a product in the user's chosen priority order.
     * Falls back to the single cheapest supplier when none were pinned, so a
     * product always resolves at least one supplier.
     *
     * @return list<SupplierPriceCandidate>
     */
    protected function prioritizedSupplierCandidates(CanonicalProduct $canonicalProduct): array
    {
        $candidates = $this->supplierCandidatesFor($canonicalProduct);
        $ids = $this->prioritizedSupplierProductIds($canonicalProduct->getKey());

        $ordered = [];

        foreach ($ids as $supplierProductId) {
            $candidate = $candidates->first(
                fn (SupplierPriceCandidate $candidate): bool => $candidate->supplierProduct->getKey() === $supplierProductId,
            );

            if ($candidate !== null) {
                $ordered[] = $candidate;
            }
        }

        if ($ordered !== []) {
            return $ordered;
        }

        $cheapest = $candidates->first();

        return $cheapest !== null ? [$cheapest] : [];
    }

    protected function formatSupplier(CanonicalProduct $record): string
    {
        $supplier = $this->chosenSupplierCandidate($record);

        if ($supplier === null) {
            return '-';
        }

        return ($supplier->supplierName ?? '?').': '.number_format($supplier->landedCost, 2).' '.$supplier->currency;
    }

    protected function formatSupermarket(CanonicalProduct $record): string
    {
        $supermarket = $this->assembleRow($record)->bestSupermarket;

        if ($supermarket === null) {
            return '-';
        }

        return ($supermarket->supermarketName ?? '?').': '.number_format($supermarket->grossPrice, 2).' '.$supermarket->currency;
    }

    protected function formatMargin(CanonicalProduct $record): string
    {
        [$margin, $percent] = $this->chosenMargin($record);

        if ($margin === null) {
            return '-';
        }

        $suffix = $percent !== null ? ' ('.number_format($percent, 1).'%)' : '';

        return number_format($margin, 2).$suffix;
    }

    protected function marginColor(CanonicalProduct $record): string
    {
        [$margin] = $this->chosenMargin($record);

        if ($margin === null) {
            return 'gray';
        }

        return $margin >= 0 ? 'success' : 'danger';
    }

    /**
     * Profit margin for the chosen (pinned or cheapest) supplier against the best
     * supermarket price, so the row and the offer stay consistent.
     *
     * @return array{0: float|null, 1: float|null} [margin, marginPercent]
     */
    protected function chosenMargin(CanonicalProduct $record): array
    {
        $supplier = $this->chosenSupplierCandidate($record);
        $supermarket = $this->assembleRow($record)->bestSupermarket;

        if ($supplier === null || $supermarket === null) {
            return [null, null];
        }

        $sellPrice = $supermarket->priceExclVat ?? $supermarket->grossPrice;
        $margin = round($sellPrice - $supplier->landedCost, 4);
        $percent = $supplier->landedCost > 0 ? round(($margin / $supplier->landedCost) * 100, 2) : null;

        return [$margin, $percent];
    }

    /**
     * Canonical product ids ordered by profit margin descending (biggest margin first).
     *
     * @return array<int, int>
     */
    protected function marginOrderedIds(): array
    {
        // Assembling every product (best supplier + supermarket price per row) is
        // expensive, and the ordering is independent of the user's supplier
        // selection — so compute it once per page load and reuse it on every
        // subsequent Livewire interaction.
        if ($this->marginOrderCache !== []) {
            return $this->marginOrderCache;
        }

        return $this->marginOrderCache = CanonicalProduct::query()
            ->get()
            ->map(fn (CanonicalProduct $canonicalProduct): MarketComparisonRow => $this->assembleRow($canonicalProduct))
            ->sortByDesc(fn (MarketComparisonRow $row): float => $row->margin ?? -INF)
            ->map(fn (MarketComparisonRow $row): int => $row->canonicalProduct->getKey())
            ->values()
            ->all();
    }

    protected function applySupplierFilter(Builder $query, mixed $supplierId): Builder
    {
        if (blank($supplierId)) {
            return $query;
        }

        return $query->whereHas(
            'supplierProducts',
            fn (Builder $query): Builder => $query->where('producer_id', $supplierId),
        );
    }

    protected function applySupermarketFilter(Builder $query, mixed $supermarketId): Builder
    {
        if (blank($supermarketId)) {
            return $query;
        }

        return $query->whereHas(
            'supermarketProducts.prices',
            fn (Builder $query): Builder => $query->where('supermarket_id', $supermarketId),
        );
    }

    protected function applyMinMarginFilter(Builder $query, mixed $minMargin): Builder
    {
        if (blank($minMargin)) {
            return $query;
        }

        $ids = CanonicalProduct::query()
            ->get()
            ->filter(fn (CanonicalProduct $canonicalProduct): bool => ($this->assembleRow($canonicalProduct)->margin ?? -INF) >= (float) $minMargin)
            ->modelKeys();

        return $query->whereKey($ids);
    }

    /**
     * @return array<int, string>
     */
    protected function supplierOptions(): array
    {
        return Supplier::query()
            ->visibleToTenant($this->getActiveTenantId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function supermarketOptions(): array
    {
        return Customer::query()
            ->withoutGlobalScope('active_tenant')
            ->global()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function getActiveTenantId(): ?int
    {
        return app(ActiveTenant::class)->id();
    }
}
