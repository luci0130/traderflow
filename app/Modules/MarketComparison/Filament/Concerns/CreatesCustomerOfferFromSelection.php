<?php

namespace App\Modules\MarketComparison\Filament\Concerns;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\NumberSequences\Services\NumberSequenceGenerator;
use App\Modules\Units\Models\Unit;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Shared "create offer from selected products" form and build flow used by the
 * Market Comparison and Best Prices — Suppliers pages, so both stay identical and
 * always set a tenant (offers are tenant-scoped, chosen at creation).
 */
trait CreatesCustomerOfferFromSelection
{
    /**
     * Offered quantity per selected canonical product, defaulting to the chosen
     * supplier's available quantity and editable in the offer modal. Keyed
     * canonicalProductId => quantity.
     *
     * @var array<int, float|int|string|null>
     */
    public array $offerQuantities = [];

    /**
     * Unit of measure per selected canonical product, defaulting to kilograms and
     * editable in the offer modal. Keyed canonicalProductId => unit id.
     *
     * @var array<int, int|string|null>
     */
    public array $offerUnits = [];

    /**
     * Margin value per selected canonical product, defaulting to the offer-level
     * margin and editable in the offer modal. Keyed canonicalProductId => margin.
     *
     * @var array<int, float|int|string|null>
     */
    public array $offerMargins = [];

    /**
     * Mirrors the offer-level margin value live, so newly selected products seed
     * their per-product margin from it and changing it propagates to all rows.
     */
    public int|float|string|null $offerMarginValue = 0;

    /**
     * Mirrors the offer-level sale price source live, so the modal table only
     * shows the per-product margin column when the margin actually applies.
     */
    public string $offerSaleMode = SupermarketOfferBuilder::SALE_FROM_PERCENTAGE;

    /**
     * Validation rule guaranteeing the offer number is unique within the chosen
     * tenant. Because the field defaults to the last offer's number (to be bumped
     * manually), this surfaces a friendly error instead of a unique-constraint
     * 500 when the user forgets to change it.
     */
    protected function uniqueOfferNumberRule(): \Closure
    {
        return fn ($get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
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
        };
    }

    /**
     * The reusable offer-review table shown in the create-offer modal, listing
     * each selected product with its supplier, prices and an editable quantity
     * and margin.
     */
    protected function offerSelectionFooter(): View
    {
        $lines = $this->offerSelectionLines($this->selectedCanonicalProducts());

        $this->syncOfferQuantities($lines);
        $this->syncOfferUnits($lines);
        $this->syncOfferMargins($lines);

        // Margin editing has moved off this modal (set later on the offer), so the
        // per-product margin column is no longer shown here.
        return view('filament.components.offer-selection', [
            'lines' => $lines,
            'margins' => $this->offerMargins,
            'units' => $this->offerUnits,
            'unitOptions' => $this->offerUnitOptions(),
            'saleMode' => $this->offerSaleMode,
            'showMargin' => false,
        ]);
    }

    /**
     * The units offered as the quantity's unit of measure, keyed id => symbol
     * (e.g. "kg") so the modal shows a compact selector next to each quantity.
     *
     * @return array<int, string>
     */
    protected function offerUnitOptions(): array
    {
        return Unit::query()->orderBy('name')->pluck('symbol', 'id')->all();
    }

    /**
     * The unit selected by default for a new offer line: kilograms.
     */
    protected function defaultOfferUnitId(): ?int
    {
        return Unit::query()->where('symbol', 'kg')->value('id');
    }

    /**
     * The next customer-offer number for the given tenant (defaults to the active
     * tenant), previewed without consuming the sequence. Null when no tenant is
     * resolved yet.
     */
    protected function previewOfferNumber(?int $tenantId = null): ?string
    {
        $tenantId ??= $this->getActiveTenantId();

        if ($tenantId === null) {
            return null;
        }

        return app(NumberSequenceGenerator::class)->preview($tenantId, 'customer_offer');
    }

    /**
     * Seed the default quantity (the supplier's available quantity) for newly
     * selected products and drop quantities for products no longer selected,
     * while preserving any quantity the user has already edited.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncOfferQuantities(array $lines): void
    {
        $selectedIds = [];

        foreach ($lines as $line) {
            $canonicalId = $line['canonical_id'];
            $selectedIds[] = $canonicalId;

            if (! array_key_exists($canonicalId, $this->offerQuantities)) {
                $this->offerQuantities[$canonicalId] = $line['quantity_available'];
            }
        }

        $this->offerQuantities = array_intersect_key($this->offerQuantities, array_flip($selectedIds));
    }

    /**
     * Seed the default unit (kilograms) for newly selected products and drop units
     * for products no longer selected, while preserving any unit the user has
     * already picked.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncOfferUnits(array $lines): void
    {
        $selectedIds = [];
        $defaultUnitId = $this->defaultOfferUnitId();

        foreach ($lines as $line) {
            $canonicalId = $line['canonical_id'];
            $selectedIds[] = $canonicalId;

            if (! array_key_exists($canonicalId, $this->offerUnits)) {
                $this->offerUnits[$canonicalId] = $defaultUnitId;
            }
        }

        $this->offerUnits = array_intersect_key($this->offerUnits, array_flip($selectedIds));
    }

    /**
     * Seed the default margin (the offer-level margin) for newly selected
     * products and drop margins for products no longer selected, while
     * preserving any per-product margin the user has already edited.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncOfferMargins(array $lines): void
    {
        $selectedIds = [];

        foreach ($lines as $line) {
            $canonicalId = $line['canonical_id'];
            $selectedIds[] = $canonicalId;

            if (! array_key_exists($canonicalId, $this->offerMargins)) {
                $this->offerMargins[$canonicalId] = $this->offerMarginValue;
            }
        }

        $this->offerMargins = array_intersect_key($this->offerMargins, array_flip($selectedIds));
    }

    /**
     * The offer creation form, shared by both pages.
     *
     * @return array<int, Grid|Hidden>
     */
    protected function customerOfferFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
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
                        ->native(false)
                        // The offer number is per-tenant, so refresh its preview whenever the
                        // tenant changes (and fill it once a tenant is picked, since a
                        // super-admin starts with no active tenant).
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $set('offer_number', filled($state) ? $this->previewOfferNumber((int) $state) : null);
                        }),
                    Select::make('customer_id')
                        ->label(__('Customer'))
                        ->options(fn (): array => $this->offerCustomerOptions())
                        ->required()
                        ->searchable(),
                ]),
            Grid::make(3)
                ->schema([
                    TextInput::make('offer_number')
                        // Prefill with the next auto-generated number so the user can see
                        // which id the offer will get. Left unchanged, it is treated as
                        // auto (the sequence is consumed on create); edited, it overrides.
                        ->default(fn (): ?string => $this->previewOfferNumber())
                        ->helperText(__('Auto-generated; change it to override.'))
                        ->rule($this->uniqueOfferNumberRule())
                        ->maxLength(255),
                    DatePicker::make('valid_until')
                        ->default(fn (): CarbonInterface => today()->addDays(7)),
                    Select::make('currency')
                        ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                        ->default('RON'),
                ]),
            // Sale price source and margin are set later (on the offer itself), so
            // they are hidden here but still submitted with their defaults so the
            // builder can seed an initial sale price.
            Hidden::make('sale_mode')
                ->default(SupermarketOfferBuilder::SALE_FROM_PERCENTAGE),
            Hidden::make('margin_value')
                ->default(0),
        ];
    }

    /**
     * Build a draft offer from a selection and redirect to it. Returns false
     * (after a warning) when nothing is selected. The selection is
     * canonicalProductId => ordered supplierProductIds (priority #1 first);
     * a scalar value (from pages that pick a single supplier) is accepted and
     * normalized to a one-element list.
     *
     * @param  array<int, list<int>|int>  $selection
     * @param  array<string, mixed>  $data
     */
    protected function createCustomerOfferFromSelection(array $selection, array $data): bool
    {
        if ($selection === []) {
            Notification::make()
                ->title(__('No products selected'))
                ->body(__('Tick a product (or pick a supplier) to include it in the offer.'))
                ->warning()
                ->send();

            return false;
        }

        // The offer-number field is prefilled with the previewed next number; if
        // the user left it unchanged, blank it so the model auto-generates and
        // actually consumes the sequence (otherwise the number is a manual override).
        if (filled($data['offer_number'] ?? null)
            && trim((string) $data['offer_number']) === $this->previewOfferNumber((int) $data['tenant_id'])) {
            $data['offer_number'] = null;
        }

        // Accept both the prioritized-list shape (Market Comparison) and the
        // single-supplier scalar shape (Best Prices — Suppliers page).
        $supplierPriorities = array_map(
            fn (array|int $value): array => is_array($value) ? array_values(array_map('intval', $value)) : [(int) $value],
            $selection,
        );

        $canonicalProducts = CanonicalProduct::query()
            ->whereKey(array_keys($selection))
            ->get();

        $offer = app(SupermarketOfferBuilder::class)->build(
            $canonicalProducts,
            $data,
            (int) $data['tenant_id'],
            supplierPriorities: $supplierPriorities,
            quantities: $this->offerQuantities,
            margins: $this->offerMargins,
            units: $this->offerUnits,
        );

        $this->offerQuantities = [];
        $this->offerUnits = [];
        $this->offerMargins = [];
        $this->offerMarginValue = 0;
        $this->offerSaleMode = SupermarketOfferBuilder::SALE_FROM_PERCENTAGE;

        Notification::make()
            ->title(__('Customer offer created'))
            ->body(__('Draft offer :id was created.', ['id' => $offer->getKey()]))
            ->success()
            ->send();

        $this->redirect(CustomerOfferResource::getUrl('edit', ['record' => $offer]));

        return true;
    }

    /**
     * The canonical products currently selected for the offer.
     *
     * @return EloquentCollection<int, CanonicalProduct>
     */
    abstract protected function selectedCanonicalProducts(): EloquentCollection;

    /**
     * One review line per selected canonical product. Each line must include a
     * `canonical_id` and a `quantity_available` (used to default the editable
     * offer quantity).
     *
     * @param  EloquentCollection<int, CanonicalProduct>  $records
     * @return array<int, array<string, mixed>>
     */
    abstract protected function offerSelectionLines(EloquentCollection $records): array;

    /**
     * The customers selectable as the offer recipient on this page.
     *
     * @return array<int, string>
     */
    abstract protected function offerCustomerOptions(): array;

    abstract protected function getActiveTenantId(): ?int;
}
