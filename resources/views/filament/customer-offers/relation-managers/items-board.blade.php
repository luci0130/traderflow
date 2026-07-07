@php
    use App\Support\Countries;
    use Illuminate\Support\Facades\Storage;

    $items = $this->boardItems();
    $groups = $this->supplierGroups();
    $editsSell = $this->editsSell();
    $editsSourcing = $this->editsSourcing();

    $fmt = fn ($value) => $value !== null && $value !== '' ? number_format((float) $value, 2) : '—';

    // Soft "cream" treatment for editable cells: legible, not shouty. It reads as
    // clearly editable without the harsh amber block the earlier design used.
    $editable = 'block w-full rounded-lg border-0 bg-warning-50 px-2.5 py-1.5 text-sm font-medium text-gray-900 shadow-sm ring-1 ring-inset ring-warning-300/70 transition focus:bg-white focus:ring-2 focus:ring-warning-500 dark:bg-warning-400/10 dark:text-white dark:ring-warning-400/30 dark:focus:bg-white/5';
@endphp

<div>
    <x-filament::section>
    <x-slot name="heading">{{ __('Products & sourcing') }}</x-slot>
    <x-slot name="description">
        {{ __('Contact the suppliers in priority order and fill in the landed cost and the quantity you secured.') }}
        <span class="ml-1 inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-block h-3 w-4 rounded ring-1 ring-inset ring-warning-300 bg-warning-50 dark:bg-warning-500/10"></span>
            {{ __('highlighted fields are editable') }}
        </span>
    </x-slot>

    @if ($items->isEmpty())
        <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('This offer has no product lines yet.') }}</div>
    @else
        <div x-data="{ tab: 'products' }">
            <div class="mb-4 flex gap-2">
                <button type="button" x-on:click="tab = 'products'"
                    :class="tab === 'products' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ __('Products') }}</button>
                <button type="button" x-on:click="tab = 'suppliers'"
                    :class="tab === 'suppliers' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ __('Suppliers') }}</button>
            </div>

            {{-- Products view: every product line is its own expandable card. The
                 header is the product summary; the suppliers are the purchase
                 options nested inside a discreet grey area. --}}
            <div x-show="tab === 'products'" class="space-y-3">
                @foreach ($items as $item)
                    @php
                        $unit = $item->unit?->symbol;

                        // The product-level stats reflect only the suppliers the seller
                        // kept in the order (checkbox), not every supplier of the product.
                        $included = $item->suppliers->filter(fn ($s) => $s->include_in_order);

                        $prices = $included->pluck('unit_price')->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p);
                        $avgPrice = $prices->isNotEmpty() ? $prices->avg() : null;

                        $landedFilled = $included->filter(fn ($s) => $s->landed_cost !== null);
                        $securedWeight = (float) $landedFilled->sum(fn ($s) => (float) ($s->secured_quantity ?? 0));
                        $avgLanded = $landedFilled->isEmpty()
                            ? null
                            : ($securedWeight > 0
                                ? $landedFilled->sum(fn ($s) => (float) $s->secured_quantity * (float) $s->landed_cost) / $securedWeight
                                : $landedFilled->avg(fn ($s) => (float) $s->landed_cost));

                        $securedTotal = (float) $included->sum(fn ($s) => (float) ($s->secured_quantity ?? 0));
                        $desired = (float) ($item->quantity ?? 0);
                        $securedPct = $desired > 0 ? min(100, round(($securedTotal / $desired) * 100)) : 0;
                        $securedColor = $securedPct >= 100 ? 'bg-success-500' : ($securedPct > 0 ? 'bg-warning-500' : 'bg-gray-300 dark:bg-white/20');

                        $imageUrl = $item->product?->displayImageUrl();

                        // Product country of origin (from its supplier products) — used for the
                        // flag next to the name. Hidden for now; re-enable together with the
                        // <img> in the header below. Distinct from the supplier's own location.
                        // $originCountry = Countries::normalize($item->suppliers->map(fn ($s) => $s->supplierProduct?->country_of_origin)->filter()->first());
                        // $originFlag = Countries::flagUrl($originCountry);
                        // $originLabel = Countries::label($originCountry);
                    @endphp
                    <div x-data="{ open: true }" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
                        {{-- Product header / summary --}}
                        <div class="flex items-center gap-3 px-3 py-3 sm:gap-4 sm:px-4"
                            x-data="{
                                purchase: @js((float) ($item->purchase_price ?? 0)),
                                sale: @js($item->sale_price !== null ? (float) $item->sale_price : null),
                                margin: @js($item->margin_percent !== null ? (float) $item->margin_percent : null),
                                get marginClass() {
                                    if (this.margin === null) return 'text-gray-400';
                                    if (this.margin >= 20) return 'text-success-600 dark:text-success-400';
                                    if (this.margin >= 5) return 'text-warning-600 dark:text-warning-400';
                                    return 'text-danger-600 dark:text-danger-400';
                                },
                            }">
                            <button type="button" x-on:click="open = ! open" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:ring-white/10 dark:hover:bg-white/10">
                                <x-filament::icon x-show="! open" icon="heroicon-m-chevron-right" class="h-4 w-4" />
                                <x-filament::icon x-show="open" x-cloak icon="heroicon-m-chevron-down" class="h-4 w-4" />
                            </button>

                            <div class="h-11 w-11 shrink-0 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-inset ring-gray-200 dark:bg-white/10 dark:ring-white/10">
                                @if ($imageUrl)
                                    <img src="{{ $imageUrl }}" alt="" class="h-full w-full object-cover" />
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-gray-300 dark:text-white/30">
                                        <x-filament::icon icon="heroicon-o-cube" class="h-5 w-5" />
                                    </div>
                                @endif
                            </div>

                            <div class="min-w-[8rem] flex-1" title="{{ $item->product?->name }}">
                                <div class="flex items-center gap-1.5 font-semibold text-gray-900 dark:text-white">
                                    <span class="truncate">{{ $item->product?->name ?? '—' }}</span>
                                    {{-- Product country-of-origin flag — hidden for now, may re-enable later.
                                    @if ($originFlag !== null)
                                        <img src="{{ $originFlag }}" alt="{{ $originCountry }}" title="{{ $originLabel }}" loading="lazy"
                                            class="h-3 w-[1.125rem] shrink-0 rounded-[2px] object-cover ring-1 ring-black/10 dark:ring-white/20" />
                                    @endif
                                    --}}
                                </div>
                                <div class="text-xs text-gray-400">{{ $item->suppliers->count() }} {{ trans_choice('supplier|suppliers', $item->suppliers->count()) }}</div>
                            </div>

                            {{-- Stat cells: every column shares space equally and centres its
                                 value beneath its label for an even, airy layout. --}}
                            <div class="hidden flex-1 text-center md:block">
                                <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Desired qty') }}</div>
                                <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $fmt($item->quantity) }} <span class="text-xs font-normal text-gray-400">{{ $unit }}</span></div>
                            </div>
                            <div class="hidden flex-1 text-center lg:block">
                                <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Avg price') }}</div>
                                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $fmt($avgPrice) }}</div>
                            </div>
                            <div class="hidden flex-1 text-center lg:block">
                                <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Avg landed') }}</div>
                                <div @class(['mt-1', 'text-gray-600 dark:text-gray-300' => $avgLanded !== null, 'text-gray-300 dark:text-white/30' => $avgLanded === null])>{{ $fmt($avgLanded) }}</div>
                            </div>

                            @if ($editsSell)
                                {{-- Alpine owns this two-way pair (wire:ignore) so Livewire morphs
                                     never clobber the values; each field recomputes the other and
                                     persists via $wire. --}}
                                <div class="flex-1 text-center">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Sale price') }}</div>
                                    <span wire:ignore class="mx-auto mt-1 block max-w-[7rem]">
                                        <input type="number" step="any" min="0" x-model.number="sale"
                                            x-on:change="margin = purchase > 0 ? +(((sale - purchase) / purchase) * 100).toFixed(2) : 0; $wire.saveSale({{ $item->id }}, 'sale_price', sale)"
                                            class="{{ $editable }} text-center" />
                                    </span>
                                </div>
                                <div class="hidden flex-1 text-center sm:block">
                                    <div class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Margin %') }}</div>
                                    <span wire:ignore class="mx-auto mt-1 block max-w-[7rem]">
                                        <input type="number" step="any" x-model.number="margin" :class="marginClass"
                                            x-on:change="sale = +(purchase * (1 + (margin / 100))).toFixed(2); $wire.saveSale({{ $item->id }}, 'margin_percent', margin)"
                                            class="{{ $editable }} text-center !font-semibold" />
                                    </span>
                                </div>
                            @endif

                            {{-- Secured qty + progress --}}
                            <div class="flex-1 text-center">
                                <div class="flex items-baseline justify-center gap-1.5">
                                    <span class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('Secured') }}</span>
                                    <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $securedPct }}%</span>
                                </div>
                                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $fmt($securedTotal) }} <span class="text-xs font-normal text-gray-400">/ {{ $fmt($item->quantity) }}</span></div>
                                <div class="mx-auto mt-1.5 h-1.5 w-full max-w-[8rem] overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                    <div class="h-full rounded-full transition-all {{ $securedColor }}" style="width: {{ $securedPct }}%"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Supplier area: the purchase options for this product --}}
                        <div x-show="open" x-cloak class="border-t border-gray-100 bg-gray-50/70 px-3 py-2 sm:px-4 dark:border-white/5 dark:bg-white/[0.02]">
                            @include('filament.customer-offers.partials.sourcing-supplier-table', [
                                'rows' => $item->suppliers,
                                'nameColumn' => 'supplier',
                                'editsSourcing' => $editsSourcing,
                                'editsSell' => $editsSell,
                                'fmt' => $fmt,
                                'inputClass' => $editable,
                            ])
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Suppliers view: supplier parent + the products it is contacted for. --}}
            <div x-show="tab === 'suppliers'" x-cloak class="space-y-3">
                @foreach ($groups as $group)
                    <div x-data="{ open: true }" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
                        <button type="button" x-on:click="open = ! open" class="flex w-full items-center gap-2 px-4 py-3 text-left">
                            <x-filament::icon x-show="! open" icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-400" />
                            <x-filament::icon x-show="open" x-cloak icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-400" />
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $group['supplier']?->name ?? '—' }}</span>
                        </button>
                        <div x-show="open" x-cloak class="border-t border-gray-100 bg-gray-50/70 px-3 py-2 sm:px-4 dark:border-white/5 dark:bg-white/[0.02]">
                            @include('filament.customer-offers.partials.sourcing-supplier-table', [
                                'rows' => $group['rows'],
                                'nameColumn' => 'product',
                                'editsSourcing' => $editsSourcing,
                                'editsSell' => $editsSell,
                                'fmt' => $fmt,
                                'inputClass' => $editable,
                            ])
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    </x-filament::section>
</div>
