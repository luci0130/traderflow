@php
    $lines = collect($lines);
    $missingSupplier = $lines->where('has_supplier', false)->count();
    // Market Comparison provides a prioritized list of suppliers per product;
    // supplier-only pages (Best Prices) send a single supplier and no list.
    $isPrioritized = $lines->isNotEmpty() && array_key_exists('suppliers', $lines->first());
    // The supermarket (sell side) columns only apply to pages that resolve them.
    $showSupermarket = $lines->isNotEmpty() && array_key_exists('supermarket', $lines->first());
    $showMargin = $showMargin ?? false;
    $margins = $margins ?? [];
    $saleMode = $saleMode ?? \App\Modules\MarketComparison\Services\SupermarketOfferBuilder::SALE_FROM_PERCENTAGE;
    $isFixedMargin = $saleMode === \App\Modules\MarketComparison\Services\SupermarketOfferBuilder::SALE_FROM_FIXED;
@endphp

<div class="fi-offer-selection mb-4">
    <p class="mb-2 text-sm text-gray-600 dark:text-gray-300">
        {{ trans_choice(':count product selected for the offer|:count products selected for the offer', $lines->count(), ['count' => $lines->count()]) }}
    </p>

    @if ($lines->isEmpty())
        <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No products selected.') }}</div>
    @elseif ($isPrioritized)
        {{-- Market Comparison: one row per product, expandable to its prioritized suppliers. --}}
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <th class="w-8 px-2 py-2"></th>
                        <th class="px-3 py-2 font-medium">{{ __('Product') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Country') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Average product price') }}</th>
                        @if ($showSupermarket)
                            <th class="px-3 py-2 font-medium">{{ __('Best supermarket (sell)') }}</th>
                        @endif
                        <th class="px-3 py-2 font-medium">{{ __('Quantity') }}</th>
                    </tr>
                </thead>
                @foreach ($lines as $line)
                    <tbody x-data="{ open: false }" class="border-t border-gray-200 dark:border-white/10">
                        <tr @class(['bg-danger-50 dark:bg-danger-500/10' => ! $line['has_supplier']])>
                            <td class="px-2 py-2 align-middle">
                                @if (! empty($line['suppliers']))
                                    <button type="button" x-on:click="open = ! open" class="mx-auto flex h-6 w-6 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">
                                        <x-filament::icon x-show="! open" icon="heroicon-m-chevron-right" class="h-4 w-4" />
                                        <x-filament::icon x-show="open" x-cloak icon="heroicon-m-chevron-down" class="h-4 w-4" />
                                    </button>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                {{ $line['product'] }}
                                @if (filled($line['packaging']))
                                    <span class="text-gray-400">· {{ $line['packaging'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $line['country'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                @if ($line['avg_price'] !== null)
                                    {{ number_format((float) $line['avg_price'], 2) }} {{ $line['supplier_currency'] }}
                                @else
                                    -
                                @endif
                            </td>
                            @if ($showSupermarket)
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                    @if (($line['supermarket'] ?? null) !== null)
                                        {{ $line['supermarket'] }}: {{ number_format((float) $line['supermarket_price'], 2) }} {{ $line['supermarket_currency'] }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                @if ($line['has_supplier'])
                                    <input
                                        type="number"
                                        min="0"
                                        step="any"
                                        @if ($line['quantity_available'] !== null) max="{{ $line['quantity_available'] }}" @endif
                                        wire:model="offerQuantities.{{ $line['canonical_id'] }}"
                                        class="fi-input block w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                    />
                                    @if ($line['quantity_available'] !== null)
                                        <span class="mt-1 block text-xs text-gray-400">
                                            {{ __('Max :qty', ['qty' => number_format((float) $line['quantity_available'], 2)]) }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-danger-600 dark:text-danger-400">{{ __('No active supplier — will be skipped') }}</span>
                                @endif
                            </td>
                        </tr>
                        @if (! empty($line['suppliers']))
                            <tr x-show="open" x-cloak class="bg-gray-50 dark:bg-white/5">
                                <td></td>
                                <td colspan="{{ $showSupermarket ? 5 : 4 }}" class="px-3 pb-3">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="text-left uppercase tracking-wide text-gray-400">
                                                <th class="py-1 pr-3 font-medium">{{ __('Priority') }}</th>
                                                <th class="py-1 pr-3 font-medium">{{ __('Supplier') }}</th>
                                                <th class="py-1 pr-3 font-medium">{{ __('Country') }}</th>
                                                <th class="py-1 pr-3 font-medium">{{ __('Product price') }}</th>
                                                <th class="py-1 pr-3 font-medium">{{ __('Available quantity') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                            @foreach ($line['suppliers'] as $supplier)
                                                <tr>
                                                    <td class="py-1 pr-3">
                                                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-warning-500 text-[11px] font-semibold text-white">{{ $supplier['priority'] }}</span>
                                                    </td>
                                                    <td class="py-1 pr-3 text-gray-900 dark:text-white">{{ $supplier['name'] ?? '-' }}</td>
                                                    <td class="py-1 pr-3 text-gray-500 dark:text-gray-400">{{ $supplier['country'] ?? '-' }}</td>
                                                    <td class="py-1 pr-3 text-gray-900 dark:text-white">{{ number_format((float) $supplier['price'], 2) }} {{ $supplier['currency'] }}</td>
                                                    <td class="py-1 pr-3 text-gray-900 dark:text-white">{{ $supplier['quantity_available'] !== null ? number_format((float) $supplier['quantity_available'], 2) : '-' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>

        @if ($missingSupplier > 0)
            <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">
                {{ trans_choice(':count selected product has no active supplier and will not be added to the offer.|:count selected products have no active supplier and will not be added to the offer.', $missingSupplier, ['count' => $missingSupplier]) }}
            </p>
        @endif
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <th class="px-3 py-2 font-medium">{{ __('Product') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Country') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Supplier (buy)') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Landed cost') }}</th>
                        @if ($showSupermarket)
                            <th class="px-3 py-2 font-medium">{{ __('Best supermarket (sell)') }}</th>
                        @endif
                        <th class="px-3 py-2 font-medium">{{ __('Quantity') }}</th>
                        @if ($showMargin)
                            <th class="px-3 py-2 font-medium">{{ __('Margin value') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($lines as $line)
                        <tr @class(['bg-danger-50 dark:bg-danger-500/10' => ! $line['has_supplier']])>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                {{ $line['product'] }}
                                @if (filled($line['packaging']))
                                    <span class="text-gray-400">· {{ $line['packaging'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $line['country'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                @if ($line['has_supplier'])
                                    {{ $line['supplier'] ?? '?' }}
                                @else
                                    <span class="text-danger-600 dark:text-danger-400">{{ __('No active supplier — will be skipped') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                @if ($line['has_supplier'])
                                    {{ number_format((float) $line['landed_cost'], 2) }} {{ $line['supplier_currency'] }}
                                @else
                                    -
                                @endif
                            </td>
                            @if ($showSupermarket)
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                    @if (($line['supermarket'] ?? null) !== null)
                                        {{ $line['supermarket'] }}: {{ number_format((float) $line['supermarket_price'], 2) }} {{ $line['supermarket_currency'] }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-2 text-gray-900 dark:text-white">
                                @if ($line['has_supplier'])
                                    <input
                                        type="number"
                                        min="0"
                                        step="any"
                                        @if ($line['quantity_available'] !== null) max="{{ $line['quantity_available'] }}" @endif
                                        wire:model="offerQuantities.{{ $line['canonical_id'] }}"
                                        class="fi-input block w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                    />
                                    @if ($line['quantity_available'] !== null)
                                        <span class="mt-1 block text-xs text-gray-400">
                                            {{ __('Max :qty', ['qty' => number_format((float) $line['quantity_available'], 2)]) }}
                                        </span>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            @if ($showMargin)
                                <td class="px-3 py-2 text-gray-900 dark:text-white">
                                    @if ($line['has_supplier'])
                                        @php
                                            $landedCost = (float) $line['landed_cost'];
                                            $marginValue = (float) ($margins[$line['canonical_id']] ?? 0);
                                            $salePrice = $isFixedMargin
                                                ? $landedCost + $marginValue
                                                : $landedCost * (1 + ($marginValue / 100));
                                        @endphp
                                        <input
                                            type="number"
                                            min="0"
                                            step="any"
                                            wire:model.live.debounce.400ms="offerMargins.{{ $line['canonical_id'] }}"
                                            class="fi-input block w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                        />
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Sale price') }}: {{ number_format($salePrice, 2) }} {{ $line['supplier_currency'] }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($missingSupplier > 0)
            <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">
                {{ trans_choice(':count selected product has no active supplier and will not be added to the offer.|:count selected products have no active supplier and will not be added to the offer.', $missingSupplier, ['count' => $missingSupplier]) }}
            </p>
        @endif
    @endif
</div>
