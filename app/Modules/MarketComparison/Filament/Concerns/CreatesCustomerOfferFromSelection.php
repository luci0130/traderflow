<?php

namespace App\Modules\MarketComparison\Filament\Concerns;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
        $this->syncOfferMargins($lines);

        return view('filament.components.offer-selection', [
            'lines' => $lines,
            'margins' => $this->offerMargins,
            'saleMode' => $this->offerSaleMode,
            'showMargin' => in_array($this->offerSaleMode, [
                SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                SupermarketOfferBuilder::SALE_FROM_FIXED,
            ], true),
        ]);
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
     * @return array<int, DatePicker|Select|TextInput>
     */
    protected function customerOfferFormSchema(): array
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
                ->label(__('Customer'))
                ->options(fn (): array => $this->offerCustomerOptions())
                ->required()
                ->searchable(),
            TextInput::make('offer_number')
                ->placeholder(__('Auto-generated'))
                ->helperText(__('Leave blank to auto-generate from the tenant sequence; type a value to override.'))
                ->rule($this->uniqueOfferNumberRule())
                ->maxLength(255),
            DatePicker::make('valid_until')
                ->default(fn (): CarbonInterface => today()->addDays(7)),
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
                ->afterStateUpdated(fn ($state) => $this->offerSaleMode = (string) $state)
                ->required(),
            TextInput::make('margin_value')
                ->label(__('Margin value'))
                ->helperText(__('Applied to every product; adjust individual products in the table below.'))
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->live(onBlur: true)
                // The offer-level margin is the bulk default: changing it updates every
                // per-product margin that still matches the previous offer margin (i.e.
                // hasn't been individually edited), so manual per-product edits survive.
                ->afterStateUpdated(function ($state): void {
                    $previous = $this->offerMarginValue;

                    foreach ($this->offerMargins as $canonicalId => $value) {
                        if ((string) $value === (string) $previous) {
                            $this->offerMargins[$canonicalId] = $state;
                        }
                    }

                    $this->offerMarginValue = $state;
                })
                ->visible(fn ($get): bool => in_array($get('sale_mode'), [
                    SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                    SupermarketOfferBuilder::SALE_FROM_FIXED,
                ], true)),
        ];
    }

    /**
     * Build a draft offer from a canonicalProductId => supplierProductId map and
     * redirect to it. Returns false (after a warning) when nothing is selected.
     *
     * @param  array<int, int>  $selection
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

        $canonicalProducts = CanonicalProduct::query()
            ->whereKey(array_keys($selection))
            ->get();

        $offer = app(SupermarketOfferBuilder::class)->build(
            $canonicalProducts,
            $data,
            (int) $data['tenant_id'],
            supplierOverrides: array_map('intval', $selection),
            quantities: $this->offerQuantities,
            margins: $this->offerMargins,
        );

        $this->offerQuantities = [];
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
