@php
    $record = $getRecord();
    $canonicalId = $record?->getKey();
    $isExpanded = $canonicalId !== null && array_key_exists($canonicalId, $this->expandedCanonicalIds);
    $supermarkets = $isExpanded ? ($this->expandedCanonicalIds[$canonicalId] ?? []) : [];
@endphp

@if ($isExpanded)
    <div class="fi-best-prices-supermarket-breakdown my-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center gap-2 border-b border-gray-100 bg-emerald-50/70 px-4 py-3 dark:border-white/5 dark:bg-emerald-500/10">
            <x-filament::icon icon="heroicon-m-building-storefront" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                {{ __('Supermarkets') }}
            </span>
            <span class="text-xs font-medium text-emerald-600/70 dark:text-emerald-400/70">{{ __('sell side') }}</span>
            <span class="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-100 px-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                {{ count($supermarkets) }}
            </span>
        </div>

        @if (count($supermarkets) === 0)
            <div class="px-4 py-5 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No recent supermarket prices.') }}</div>
        @else
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/80 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-white/5 dark:bg-white/5 dark:text-gray-400">
                        <th class="w-14 px-4 py-2.5 text-center">{{ __('Pick') }}</th>
                        <th class="px-4 py-2.5">{{ __('Supermarket') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Price') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('Price excl. VAT') }}</th>
                        <th class="px-4 py-2.5">{{ __('Observed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($supermarkets as $index => $supermarket)
                        @php $isSelected = $this->isSupermarketCandidateSelected($canonicalId, $supermarket['id']); @endphp
                        <tr
                            wire:key="supermarket-{{ $canonicalId }}-{{ $supermarket['id'] }}"
                            @class([
                                'group border-b border-gray-50 transition-colors last:border-0 dark:border-white/5',
                                'bg-emerald-50/60 dark:bg-emerald-500/10' => $isSelected,
                                'hover:bg-gray-50 dark:hover:bg-white/5' => ! $isSelected,
                            ])
                        >
                            <td class="px-4 py-3">
                                <div class="flex justify-center">
                                    <button
                                        type="button"
                                        wire:click.stop="toggleSupermarketCandidate({{ $canonicalId }}, {{ $supermarket['id'] }})"
                                        @class([
                                            'flex h-6 w-6 items-center justify-center rounded-full border text-xs font-semibold transition',
                                            'border-emerald-500 bg-emerald-500 text-white shadow-sm' => $isSelected,
                                            'border-gray-300 text-gray-400 hover:border-emerald-400 hover:text-emerald-500 dark:border-white/20 dark:text-gray-500' => ! $isSelected,
                                        ])
                                        title="{{ $isSelected ? __('Selected for the offer') : __('Pick this supermarket') }}"
                                    >
                                        @if ($isSelected)
                                            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                                        @else
                                            <span aria-hidden="true">&ndash;</span>
                                        @endif
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $supermarket['name'] ?? '-' }}</span>
                                @if ($index === 0)
                                    <span class="ml-1.5 inline-flex items-center rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                                        {{ __('Best') }}
                                    </span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format((float) $supermarket['gross_price'], 2) }}</span>
                                <span class="text-xs text-gray-400">{{ $supermarket['currency'] }}</span>
                                @if ($supermarket['is_promo'])
                                    <span class="ml-0.5 text-amber-500" title="{{ __('Promo') }}">★</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">
                                {{ $supermarket['price_excl_vat'] !== null ? number_format((float) $supermarket['price_excl_vat'], 2).' '.$supermarket['currency'] : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-gray-400">{{ $supermarket['observed_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
