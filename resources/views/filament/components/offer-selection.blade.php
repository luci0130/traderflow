@php
    $lines = collect($lines);
    $missingSupplier = $lines->where('has_supplier', false)->count();
    // The supermarket (sell side) columns only apply to pages that resolve them
    // (Market Comparison). Supplier-only pages omit the key entirely.
    $showSupermarket = $lines->isNotEmpty() && array_key_exists('supermarket', $lines->first());
    // The per-product margin column only shows when the sale price is derived
    // from a margin (percentage / fixed), mirroring the offer-level field.
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
