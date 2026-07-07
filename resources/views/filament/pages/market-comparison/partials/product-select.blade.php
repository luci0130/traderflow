@php
    $record = $getRecord();
    $canonicalId = $record?->getKey();
    $isSelected = $canonicalId !== null && $this->isProductSelected($canonicalId);
    $count = $isSelected ? count($this->prioritizedSupplierProductIds($canonicalId)) : 0;
@endphp

{{-- Read-only state indicator: it reflects whether the product has at least one
     prioritized supplier. Selection is driven from the expanded supplier list. --}}
<div
    @class([
        'flex h-5 min-w-5 items-center justify-center rounded-md border px-1 text-xs font-semibold',
        'border-primary-500 bg-primary-500 text-white shadow-sm' => $isSelected,
        'border-gray-300 bg-white dark:border-white/20 dark:bg-white/5' => ! $isSelected,
    ])
    title="{{ $isSelected ? __(':count supplier(s) selected', ['count' => $count]) : __('Expand the row and pick suppliers to include this product') }}"
>
    @if ($isSelected)
        {{ $count }}
    @endif
</div>
