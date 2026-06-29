<?php

namespace App\Modules\BestPrices\Filament\Pages;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Models\Tenant;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Data\SupermarketPriceCandidate;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Queries\SupermarketBestPriceQuery;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Support\Tenancy\ActiveTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Sell side of the catalog: every canonical product with the highest-paying
 * recent supermarket shelf price, expandable to all observed prices and
 * selectable into a draft supermarket offer.
 */
class BestPricesSupermarket extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'best-prices-supermarket';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupermarketPrice') ?? false;
    }

    protected Width|string|null $maxContentWidth = 'full';

    /**
     * Best supermarket candidate memoized per canonical product id for the request.
     *
     * @var array<int, SupermarketPriceCandidate|null>
     */
    protected array $bestCandidateCache = [];

    /**
     * Full ranked candidate list memoized per canonical product id.
     *
     * @var array<int, Collection<int, SupermarketPriceCandidate>>
     */
    protected array $candidatesCache = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    public array $expandedCanonicalIds = [];

    /**
     * Hand-picked supermarket price per canonical product: canonicalProductId => supermarketPriceId.
     *
     * @var array<int, int>
     */
    public array $selectedSupermarketByCanonical = [];

    public function getTitle(): string
    {
        return __('Best Prices — Supermarket');
    }

    public static function getNavigationLabel(): string
    {
        return __('Best Prices — Supermarket');
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
                    View::make('filament.pages.best-prices.partials.supermarket-select')
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
                    TextColumn::make('best_supermarket')
                        ->label(__('Best supermarket'))
                        ->state(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->supermarketName ?? '-')
                        ->badge()
                        ->color('success'),
                    TextColumn::make('gross_price')
                        ->label(__('Shelf price'))
                        ->state(fn (CanonicalProduct $record): string => $this->formatGrossPrice($record))
                        ->badge(),
                    TextColumn::make('price_excl_vat')
                        ->label(__('Price excl. VAT'))
                        ->state(fn (CanonicalProduct $record): string => $this->formatExclVat($record)),
                    TextColumn::make('observed_at')
                        ->label(__('Observed'))
                        ->state(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->observedAt ?? '-'),
                    TextColumn::make('promo')
                        ->label(__('Promo'))
                        ->state(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->isPromo ? __('Yes') : '-')
                        ->badge()
                        ->color(fn (CanonicalProduct $record): string => $this->bestCandidate($record)?->isPromo ? 'warning' : 'gray'),
                    TextColumn::make('supermarkets_count')
                        ->label(__('Supermarkets'))
                        ->state(fn (CanonicalProduct $record): int => $this->candidates($record)->count())
                        ->badge(),
                ]),
                View::make('filament.pages.best-prices.partials.supermarket-breakdown'),
            ])
            ->recordActions([
                Action::make('toggleSupermarkets')
                    ->label(__('Toggle supermarkets'))
                    ->iconButton()
                    ->color('gray')
                    ->icon(fn (CanonicalProduct $record): Heroicon => array_key_exists($record->getKey(), $this->expandedCanonicalIds)
                        ? Heroicon::ChevronUp
                        : Heroicon::ChevronDown)
                    ->action(fn (CanonicalProduct $record) => $this->toggleSupermarkets($record->getKey())),
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
                SelectFilter::make('supermarket')
                    ->label(__('Supermarket'))
                    ->options(fn (): array => $this->supermarketOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $this->applySupermarketFilter($query, $data['value'] ?? null)),
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
                ->badge(fn (): int => count($this->selectedSupermarketByCanonical))
                ->icon(Heroicon::OutlinedDocumentCurrencyEuro)
                ->disabled(fn (): bool => $this->selectedSupermarketByCanonical === [])
                ->modalHeading(__('Create supermarket offer from selected prices'))
                ->schema($this->offerFormSchema())
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    if ($this->selectedSupermarketByCanonical === []) {
                        Notification::make()
                            ->title(__('No supermarket prices selected'))
                            ->body(__('Expand a product and tick a supermarket price to include it in the offer.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $canonicalProducts = CanonicalProduct::query()
                        ->whereKey(array_keys($this->selectedSupermarketByCanonical))
                        ->get();

                    $offer = app(SupermarketOfferBuilder::class)->build(
                        $canonicalProducts,
                        $data,
                        (int) $data['tenant_id'],
                        supermarketOverrides: $this->selectedSupermarketByCanonical,
                    );

                    $this->selectedSupermarketByCanonical = [];

                    $this->notifyOfferCreated($offer);

                    $this->redirect(CustomerOfferResource::getUrl('edit', ['record' => $offer]));
                }),
        ];
    }

    /**
     * @return array<int, DatePicker|Select|TextInput>
     */
    protected function offerFormSchema(): array
    {
        return [
            Select::make('tenant_id')
                ->label(__('Tenant'))
                ->options(fn (): array => Tenant::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->default(fn (): ?int => $this->getActiveTenantId())
                ->required()
                ->searchable()
                ->native(false),
            Select::make('customer_id')
                ->label(__('Supermarket'))
                ->options(fn (): array => $this->supermarketOptions())
                ->required()
                ->searchable(),
            TextInput::make('offer_number')
                ->placeholder(__('Auto-generated'))
                ->helperText(__('Leave blank to auto-generate from the tenant sequence; type a value to override.'))
                ->rule(fn ($get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if (blank($value)) {
                        return;
                    }

                    $exists = CustomerOffer::query()
                        ->withoutGlobalScope('active_tenant')
                        ->where('tenant_id', $get('tenant_id'))
                        ->where('offer_number', $value)
                        ->exists();

                    if ($exists) {
                        $fail(__('An offer with this number already exists for this tenant. Increase the number and try again.'));
                    }
                })
                ->maxLength(255),
            DatePicker::make('valid_until')
                ->default(fn (): \Carbon\CarbonInterface => today()->addDays(7)),
            Select::make('currency')
                ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                ->default('RON'),
            Select::make('sale_mode')
                ->label(__('Sale price source'))
                ->options([
                    SupermarketOfferBuilder::SALE_FROM_SUPERMARKET => __('Best supermarket price'),
                    SupermarketOfferBuilder::SALE_FROM_PERCENTAGE => __('Landed cost + percentage'),
                    SupermarketOfferBuilder::SALE_FROM_FIXED => __('Landed cost + fixed amount'),
                ])
                ->default(SupermarketOfferBuilder::SALE_FROM_PERCENTAGE)
                ->live()
                ->required(),
            TextInput::make('margin_value')
                ->label(__('Margin value'))
                ->numeric()
                ->default(0)
                ->visible(fn ($get): bool => in_array($get('sale_mode'), [
                    SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                    SupermarketOfferBuilder::SALE_FROM_FIXED,
                ], true)),
        ];
    }

    protected function notifyOfferCreated(CustomerOffer $offer): void
    {
        Notification::make()
            ->title(__('Supermarket offer created'))
            ->body(__('Draft offer :id was created.', ['id' => $offer->getKey()]))
            ->success()
            ->send();
    }

    public function getQuery(): Builder
    {
        $query = CanonicalProduct::query()->with('category');

        $orderedIds = $this->shelfPriceOrderedIds();

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

    public function toggleSupermarkets(int $canonicalId): void
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
            ->map(fn (SupermarketPriceCandidate $candidate): array => [
                'id' => $candidate->price->getKey(),
                'name' => $candidate->supermarketName,
                'gross_price' => $candidate->grossPrice,
                'price_excl_vat' => $candidate->priceExclVat,
                'currency' => $candidate->currency,
                'observed_at' => $candidate->observedAt,
                'is_promo' => $candidate->isPromo,
            ])
            ->all();
    }

    /**
     * Pins one supermarket price per canonical product for the hand-picked
     * offer; picking a different price in the same product replaces the previous
     * choice, and re-picking the same one clears it.
     */
    public function toggleSupermarketCandidate(int $canonicalId, int $supermarketPriceId): void
    {
        if (($this->selectedSupermarketByCanonical[$canonicalId] ?? null) === $supermarketPriceId) {
            unset($this->selectedSupermarketByCanonical[$canonicalId]);

            return;
        }

        $this->selectedSupermarketByCanonical[$canonicalId] = $supermarketPriceId;
    }

    public function isSupermarketCandidateSelected(int $canonicalId, int $supermarketPriceId): bool
    {
        return ($this->selectedSupermarketByCanonical[$canonicalId] ?? null) === $supermarketPriceId;
    }

    /**
     * Main-row checkbox: include or drop a product from the offer, defaulting to
     * its best paying supermarket price. Switching to a specific price happens in
     * the expanded breakdown; both feed the same selection.
     */
    public function toggleProductSelection(int $canonicalId, int $bestSupermarketPriceId): void
    {
        if (array_key_exists($canonicalId, $this->selectedSupermarketByCanonical)) {
            unset($this->selectedSupermarketByCanonical[$canonicalId]);

            return;
        }

        $this->selectedSupermarketByCanonical[$canonicalId] = $bestSupermarketPriceId;
    }

    public function isProductSelected(int $canonicalId): bool
    {
        return array_key_exists($canonicalId, $this->selectedSupermarketByCanonical);
    }

    public function bestSupermarketPriceId(CanonicalProduct $record): ?int
    {
        return $this->bestCandidate($record)?->price->getKey();
    }

    /**
     * @return Collection<int, SupermarketPriceCandidate>
     */
    protected function candidates(CanonicalProduct $canonicalProduct): Collection
    {
        return $this->candidatesCache[$canonicalProduct->getKey()] ??= app(SupermarketBestPriceQuery::class)
            ->candidatesFor($canonicalProduct);
    }

    protected function bestCandidate(CanonicalProduct $canonicalProduct): ?SupermarketPriceCandidate
    {
        return $this->bestCandidateCache[$canonicalProduct->getKey()] ??= $this->candidates($canonicalProduct)->first();
    }

    protected function formatGrossPrice(CanonicalProduct $record): string
    {
        $candidate = $this->bestCandidate($record);

        if ($candidate === null) {
            return '-';
        }

        return number_format($candidate->grossPrice, 2).' '.$candidate->currency;
    }

    protected function formatExclVat(CanonicalProduct $record): string
    {
        $candidate = $this->bestCandidate($record);

        if ($candidate === null || $candidate->priceExclVat === null) {
            return '-';
        }

        return number_format($candidate->priceExclVat, 2).' '.$candidate->currency;
    }

    /**
     * Canonical product ids ordered by best shelf price descending (highest
     * paying first); products with no recent price fall to the bottom.
     *
     * @return array<int, int>
     */
    protected function shelfPriceOrderedIds(): array
    {
        return CanonicalProduct::query()
            ->get()
            ->sortByDesc(fn (CanonicalProduct $canonicalProduct): float => $this->bestCandidate($canonicalProduct)?->grossPrice ?? -INF)
            ->map(fn (CanonicalProduct $canonicalProduct): int => $canonicalProduct->getKey())
            ->values()
            ->all();
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
