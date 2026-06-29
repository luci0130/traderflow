<?php

namespace App\Modules\BestPrices\Filament\Pages;

use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Filament\Concerns\CreatesCustomerOfferFromSelection;
use App\Modules\MarketComparison\Data\SupplierPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupplierBestPriceQuery;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\Countries;
use App\Support\Tenancy\ActiveTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Buy side of the catalog: every canonical product with its cheapest supplier
 * (landed cost = unit price plus resolved sourcing costs), expandable to all
 * supplier candidates and selectable into a draft customer offer.
 */
class BestPricesSuppliers extends Page implements HasTable
{
    use CreatesCustomerOfferFromSelection;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'best-prices-suppliers';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupermarketPrice') ?? false;
    }

    protected Width|string|null $maxContentWidth = 'full';

    /**
     * Best supplier candidate memoized per canonical product id for the request.
     *
     * @var array<int, SupplierPriceCandidate|null>
     */
    protected array $bestCandidateCache = [];

    /**
     * Full ranked candidate list memoized per canonical product id.
     *
     * @var array<int, Collection<int, SupplierPriceCandidate>>
     */
    protected array $candidatesCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    public array $expandedCanonicalIds = [];

    /**
     * Hand-picked supplier per canonical product: canonicalProductId => supplierProductId.
     *
     * @var array<int, int>
     */
    public array $selectedSupplierByCanonical = [];

    public function getTitle(): string
    {
        return __('Best Prices — Suppliers');
    }

    public static function getNavigationLabel(): string
    {
        return __('Best Prices — Suppliers');
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
            ->query(fn (): Builder => $this->getQuery())
            ->recordTitleAttribute('name')
            ->columns([
                Split::make([
                    View::make('filament.pages.best-prices.partials.supplier-select')
                        ->grow(false),
                    TextColumn::make('category.name')
                        ->label(__('Category'))
                        ->placeholder('-')
                        ->sortable(),
                    TextColumn::make('name')
                        ->label(__('Product'))
                        ->description(fn (CanonicalProduct $record): ?string => $record->packaging_variant)
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('best_supplier')
                        ->label(__('Best supplier'))
                        ->state(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->supplierName ?? '-')
                        ->badge()
                        ->color('warning'),
                    TextColumn::make('landed_cost')
                        ->label(__('Landed cost'))
                        ->state(fn (CanonicalProduct $record): string => $this->formatLandedCost($record))
                        ->badge(),
                    TextColumn::make('unit_price')
                        ->label(__('Supplier price'))
                        ->state(fn (CanonicalProduct $record): string => $this->formatUnitPrice($record)),
                    TextColumn::make('quantity_available')
                        ->label(__('Available'))
                        ->state(fn (CanonicalProduct $record): string => $this->formatQuantity($record)),
                    TextColumn::make('valid_until')
                        ->label(__('Valid until'))
                        ->state(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->validUntil ?? __('Open')),
                    TextColumn::make('suppliers_count')
                        ->label(__('Suppliers'))
                        ->state(fn (CanonicalProduct $record): int => $this->candidates($record)->count())
                        ->badge(),
                ]),
                View::make('filament.pages.best-prices.partials.supplier-breakdown'),
            ])
            ->recordActions([
                Action::make('toggleSuppliers')
                    ->label(__('Toggle suppliers'))
                    ->iconButton()
                    ->color('gray')
                    ->icon(fn (CanonicalProduct $record): Heroicon => array_key_exists($record->getKey(), $this->expandedCanonicalIds)
                        ? Heroicon::ChevronUp
                        : Heroicon::ChevronDown)
                    ->action(fn (CanonicalProduct $record) => $this->toggleSuppliers($record->getKey())),
            ])
            ->filters([
                SelectFilter::make('product_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => ProductCategory::query()
                        ->visibleToTenant($this->getActiveTenantId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('supplier')
                    ->label(__('Supplier'))
                    ->options(fn (): array => $this->supplierOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $this->applySupplierFilter($query, $data['value'] ?? null)),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCustomerOffer')
                ->label(__('Create customer offer'))
                ->badge(fn (): int => count($this->selectedSupplierByCanonical))
                ->icon(Heroicon::OutlinedDocumentCurrencyEuro)
                ->disabled(fn (): bool => $this->selectedSupplierByCanonical === [])
                ->modalHeading(__('Create customer offer from selected suppliers'))
                ->modalWidth(Width::SevenExtraLarge)
                ->modalContentFooter(fn (): \Illuminate\Contracts\View\View => $this->offerSelectionFooter())
                ->schema($this->customerOfferFormSchema())
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    if ($this->createCustomerOfferFromSelection($this->selectedSupplierByCanonical, $data)) {
                        $this->selectedSupplierByCanonical = [];
                    }
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function offerCustomerOptions(): array
    {
        return Customer::query()
            ->visibleToTenant(null)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The canonical products currently selected for the offer.
     *
     * @return EloquentCollection<int, CanonicalProduct>
     */
    protected function selectedCanonicalProducts(): EloquentCollection
    {
        return CanonicalProduct::query()
            ->whereKey(array_keys($this->selectedSupplierByCanonical))
            ->get();
    }

    /**
     * One review line per selected canonical product, pairing it with the chosen
     * supplier (pinned, or cheapest by landed cost) shown in the offer modal.
     *
     * @param  EloquentCollection<int, CanonicalProduct>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function offerSelectionLines(EloquentCollection $records): array
    {
        return $records->map(function (CanonicalProduct $record): array {
            $supplier = $this->chosenSupplierCandidate($record);

            return [
                'canonical_id' => $record->getKey(),
                'product' => $record->name,
                'packaging' => $record->packaging_variant,
                'country' => Countries::label($record->country_of_origin),
                'supplier' => $supplier?->supplierName,
                'landed_cost' => $supplier?->landedCost,
                'supplier_currency' => $supplier?->currency,
                'quantity_available' => $supplier?->quantityAvailable,
                'has_supplier' => $supplier !== null,
            ];
        })->all();
    }

    /**
     * The supplier candidate used for a canonical product: the hand-picked one
     * when pinned, otherwise the cheapest by landed cost.
     */
    protected function chosenSupplierCandidate(CanonicalProduct $canonicalProduct): ?SupplierPriceCandidate
    {
        $candidates = $this->candidates($canonicalProduct);
        $pinnedId = $this->selectedSupplierByCanonical[$canonicalProduct->getKey()] ?? null;

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

    public function getQuery(): Builder
    {
        $query = CanonicalProduct::query()->with('category');

        $orderedIds = $this->landedCostOrderedIds();

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

    public function toggleSuppliers(int $canonicalId): void
    {
        if (array_key_exists($canonicalId, $this->expandedCanonicalIds)) {
            unset($this->expandedCanonicalIds[$canonicalId]);

            return;
        }

        $canonicalProduct = CanonicalProduct::find($canonicalId);

        if ($canonicalProduct === null) {
            return;
        }

        $this->expandedCanonicalIds[$canonicalId] = $this->candidates($canonicalProduct)
            ->map(fn (SupplierPriceCandidate $candidate): array => [
                'id' => $candidate->supplierProduct->getKey(),
                'name' => $candidate->supplierName,
                'landed_cost' => $candidate->landedCost,
                'unit_price' => $candidate->unitPrice,
                'currency' => $candidate->currency,
                'quantity' => $candidate->quantityAvailable,
                'valid_until' => $candidate->validUntil,
            ])
            ->all();
    }

    /**
     * Pins one supplier per canonical product for the hand-picked offer; picking
     * a different supplier in the same product replaces the previous choice, and
     * re-picking the same one clears it.
     */
    public function toggleSupplierCandidate(int $canonicalId, int $supplierProductId): void
    {
        if (($this->selectedSupplierByCanonical[$canonicalId] ?? null) === $supplierProductId) {
            unset($this->selectedSupplierByCanonical[$canonicalId]);

            return;
        }

        $this->selectedSupplierByCanonical[$canonicalId] = $supplierProductId;
    }

    public function isSupplierCandidateSelected(int $canonicalId, int $supplierProductId): bool
    {
        return ($this->selectedSupplierByCanonical[$canonicalId] ?? null) === $supplierProductId;
    }

    /**
     * Main-row checkbox: include or drop a product from the offer, defaulting to
     * its cheapest supplier. Switching to a specific supplier happens in the
     * expanded breakdown; both feed the same selection.
     */
    public function toggleProductSelection(int $canonicalId, int $bestSupplierProductId): void
    {
        if (array_key_exists($canonicalId, $this->selectedSupplierByCanonical)) {
            unset($this->selectedSupplierByCanonical[$canonicalId]);

            return;
        }

        $this->selectedSupplierByCanonical[$canonicalId] = $bestSupplierProductId;
    }

    public function isProductSelected(int $canonicalId): bool
    {
        return array_key_exists($canonicalId, $this->selectedSupplierByCanonical);
    }

    public function bestSupplierProductId(CanonicalProduct $record): ?int
    {
        return $this->bestCandidate($record)?->supplierProduct->getKey();
    }

    /**
     * @return Collection<int, SupplierPriceCandidate>
     */
    protected function candidates(CanonicalProduct $canonicalProduct): Collection
    {
        return $this->candidatesCache[$canonicalProduct->getKey()] ??= app(SupplierBestPriceQuery::class)
            ->candidatesFor($canonicalProduct);
    }

    protected function bestCandidate(CanonicalProduct $canonicalProduct): ?SupplierPriceCandidate
    {
        return $this->bestCandidateCache[$canonicalProduct->getKey()] ??= $this->candidates($canonicalProduct)->first();
    }

    protected function formatLandedCost(CanonicalProduct $record): string
    {
        $candidate = $this->bestCandidate($record);

        if ($candidate === null) {
            return '-';
        }

        return number_format($candidate->landedCost, 2).' '.$candidate->currency;
    }

    protected function formatUnitPrice(CanonicalProduct $record): string
    {
        $candidate = $this->bestCandidate($record);

        if ($candidate === null) {
            return '-';
        }

        return number_format($candidate->unitPrice, 2).' '.$candidate->currency;
    }

    protected function formatQuantity(CanonicalProduct $record): string
    {
        $candidate = $this->bestCandidate($record);

        if ($candidate === null || $candidate->quantityAvailable === null) {
            return '-';
        }

        return number_format($candidate->quantityAvailable, 2);
    }

    /**
     * Canonical product ids ordered by best landed cost ascending (cheapest
     * first); products with no active supplier fall to the bottom.
     *
     * @return array<int, int>
     */
    protected function landedCostOrderedIds(): array
    {
        return CanonicalProduct::query()
            ->get()
            ->sortBy(fn (CanonicalProduct $canonicalProduct): float => $this->bestCandidate($canonicalProduct)?->landedCost ?? INF)
            ->map(fn (CanonicalProduct $canonicalProduct): int => $canonicalProduct->getKey())
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

    protected function getActiveTenantId(): ?int
    {
        return app(ActiveTenant::class)->id();
    }
}
