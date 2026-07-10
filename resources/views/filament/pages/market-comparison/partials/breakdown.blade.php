@php
    $record = $getRecord();
    $canonicalId = $record?->getKey();
    $isExpanded = $canonicalId !== null && array_key_exists($canonicalId, $this->expandedCanonicalIds);
    $breakdown = $isExpanded ? ($this->expandedCanonicalIds[$canonicalId] ?? null) : null;
    $suppliers = $breakdown['suppliers'] ?? [];
    $supermarkets = $breakdown['supermarkets'] ?? [];
@endphp

@if ($isExpanded)
    <div class="fi-market-comparison-breakdown mt-2 grid gap-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 md:grid-cols-2 dark:border-white/10 dark:bg-white/5">
        <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-success-600 dark:text-success-400">
                {{ __('Supermarket prices') }}
            </p>

            @if (count($supermarkets) === 0)
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No recent supermarket prices.') }}</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-3 font-medium">{{ __('Supermarket') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Price excl. VAT') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Observed') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($supermarkets as $supermarket)
                            <tr>
                                <td class="py-1 pr-3 text-gray-900 dark:text-white">{{ $supermarket['name'] ?? '-' }}</td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-white">
                                    {{ number_format((float) ($supermarket['price_excl_vat'] ?? $supermarket['gross_price']), 2) }} {{ $supermarket['currency'] }}
                                    @if ($supermarket['is_promo']) <span class="text-warning-600">★</span> @endif
                                </td>
                                <td class="py-1 pr-3 text-gray-500 dark:text-gray-400">{{ $supermarket['observed_at'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div>
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-warning-600 dark:text-warning-400">
                {{ __('Supplier prices') }}
            </p>

            @if (count($suppliers) === 0)
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No active supplier products.') }}</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-3 font-medium">{{ __('Priority') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Supplier') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('City') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Product price') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Available') }}</th>
                            <th class="py-1 pr-3 font-medium">{{ __('Valid until') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($suppliers as $supplier)
                            @php $priority = $this->supplierPriority($canonicalId, $supplier['id']); @endphp
                            <tr @class(['bg-warning-50 dark:bg-warning-500/10' => $priority !== null])>
                                <td class="py-1 pr-3">
                                    <button
                                        type="button"
                                        wire:click.stop="toggleSupplierPriority({{ $canonicalId }}, {{ $supplier['id'] }})"
                                        @class([
                                            'flex h-6 w-6 items-center justify-center rounded-md border text-xs font-semibold transition',
                                            'border-warning-500 bg-warning-500 text-white shadow-sm' => $priority !== null,
                                            'border-gray-300 bg-white hover:border-warning-400 dark:border-white/20 dark:bg-white/5' => $priority === null,
                                        ])
                                        title="{{ $priority !== null ? __('Priority :n — click to remove', ['n' => $priority]) : __('Add to the offer at the next priority') }}"
                                    >
                                        {{ $priority ?? '' }}
                                    </button>
                                </td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-white">{{ $supplier['name'] ?? '-' }}</td>
                                @php
                                    $supplierLocation = trim(($supplier['city'] ?? '').(($supplier['city'] && $supplier['country']) ? ', ' : '').($supplier['country'] ?? ''));
                                @endphp
                                <td class="py-1 pr-3 text-gray-500 dark:text-gray-400">{{ $supplierLocation !== '' ? $supplierLocation : '-' }}</td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-white">
                                    {{ $supplier['unit_price'] !== null ? number_format((float) $supplier['unit_price'], 2).' '.$supplier['currency'] : '-' }}
                                </td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-white">
                                    {{ $supplier['quantity'] !== null ? number_format((float) $supplier['quantity'], 2) : '-' }}
                                </td>
                                <td class="py-1 pr-3 text-gray-500 dark:text-gray-400">{{ $supplier['valid_until'] ?? __('Open') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endif
