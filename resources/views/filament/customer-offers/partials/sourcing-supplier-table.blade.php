@php
    use App\Support\Countries;
    // $rows: Collection<CustomerOfferItemSupplier>
    // $nameColumn: 'supplier' (show supplier name) or 'product' (show line product)
    // $editsSourcing: bool  $editsSell: bool  $fmt: callable  $inputClass: string
    $nameLabel = $nameColumn === 'product' ? __('Product') : __('Supplier');
    $editsSell = $editsSell ?? false;
@endphp

<table class="w-full text-xs">
    <thead>
        <tr class="text-left uppercase tracking-wide text-gray-400">
            @if ($editsSell)
                <th class="py-2 pr-3 text-center font-medium" title="{{ __('Include in order') }}">{{ __('Order') }}</th>
            @endif
            <th class="py-2 pr-3 font-medium">{{ __('Priority') }}</th>
            <th class="py-2 pr-3 font-medium">{{ $nameLabel }}</th>
            {{-- Products tab shows the supplier's city; Suppliers tab shows the
                 product's country of origin. --}}
            <th class="py-2 pr-3 font-medium">{{ $nameColumn === 'product' ? __('Country of origin') : __('City') }}</th>
            <th class="py-2 pr-3 font-medium">{{ __('Product price') }}</th>
            <th class="py-2 pr-3 font-medium">{{ __('Total cost') }}</th>
            <th class="py-2 pr-3 font-medium">{{ __('Secured qty') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-200/70 dark:divide-white/5">
        @foreach ($rows as $row)
            <tr @class(['transition', 'opacity-50' => $editsSell && ! $row->include_in_order])>
                @if ($editsSell)
                    <td class="py-2 pr-3 text-center">
                        <input type="checkbox" @checked($row->include_in_order)
                            wire:change="saveInclude({{ $row->id }}, $event.target.checked)"
                            class="size-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-white/10" />
                    </td>
                @endif
                <td class="py-2 pr-3">
                    <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-warning-500 px-1.5 text-[11px] font-bold text-white shadow-sm">{{ $row->priority }}</span>
                </td>
                <td class="py-2 pr-3 font-medium text-gray-900 dark:text-white">
                    {{ $nameColumn === 'product' ? ($row->item?->product?->name ?? '—') : ($row->supplier?->name ?? '—') }}
                </td>
                @php
                    if ($nameColumn === 'product') {
                        // Suppliers tab: the product's country of origin.
                        $flagCountry = Countries::normalize($row->supplierProduct?->country_of_origin);
                        $flagUrl = Countries::flagUrl($flagCountry);
                        $locationLabel = Countries::label($flagCountry) ?? $flagCountry ?? '';
                    } else {
                        // Products tab: the supplier company location (where you contact them).
                        $flagCountry = Countries::normalize($row->supplier?->country);
                        $supplierCity = $row->supplier?->city;
                        $flagUrl = Countries::flagUrl($flagCountry);
                        $locationLabel = trim(($supplierCity ?? '').(($supplierCity && $flagCountry) ? ', ' : '').($flagCountry ?? ''));
                    }
                @endphp
                <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">
                    @if ($locationLabel !== '')
                        <span class="inline-flex items-center gap-1.5">
                            @if ($flagUrl !== null)
                                <img src="{{ $flagUrl }}" alt="{{ $flagCountry }}" loading="lazy"
                                    class="h-3 w-[1.125rem] shrink-0 rounded-[2px] object-cover ring-1 ring-black/10 dark:ring-white/20" />
                            @endif
                            <span>{{ $locationLabel }}</span>
                        </span>
                    @else
                        —
                    @endif
                </td>
                <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">{{ $row->unit_price !== null ? number_format((float) $row->unit_price, 2).' '.$row->currency : '—' }}</td>
                <td class="py-2 pr-3">
                    @if ($editsSourcing)
                        <div class="w-28">
                            <input type="number" step="any" min="0" value="{{ $row->landed_cost }}"
                                wire:change="saveSourcing({{ $row->id }}, 'landed_cost', $event.target.value)" class="{{ $inputClass }}" />
                        </div>
                    @else
                        <span class="text-gray-900 dark:text-white">{{ $fmt($row->landed_cost) }}</span>
                    @endif
                </td>
                <td class="py-2 pr-3">
                    @if ($editsSourcing)
                        <div class="w-28">
                            <input type="number" step="any" min="0" value="{{ $row->secured_quantity }}"
                                wire:change="saveSourcing({{ $row->id }}, 'secured_quantity', $event.target.value)" class="{{ $inputClass }}" />
                        </div>
                    @else
                        <span class="text-gray-900 dark:text-white">{{ $fmt($row->secured_quantity) }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
