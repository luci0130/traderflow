@php
    $record = $getRecord();
    $canonicalId = $record?->getKey();
    $bestId = $record !== null ? $this->bestSupplierProductId($record) : null;
    $isSelected = $canonicalId !== null && $this->isProductSelected($canonicalId);
@endphp

@if ($bestId !== null)
    <button
        type="button"
        wire:click.stop="toggleProductSelection({{ $canonicalId }}, {{ $bestId }})"
        @class([
            'flex h-5 w-5 items-center justify-center rounded-md border transition',
            'border-amber-500 bg-amber-500 text-white shadow-sm' => $isSelected,
            'border-gray-300 bg-white hover:border-amber-400 dark:border-white/20 dark:bg-white/5' => ! $isSelected,
        ])
        title="{{ $isSelected ? __('Included in the offer') : __('Include this product in the offer') }}"
    >
        @if ($isSelected)
            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" />
        @endif
    </button>
@else
    <span class="block w-5"></span>
@endif
