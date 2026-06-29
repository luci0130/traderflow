@php
    $stats = $this->getStats();
@endphp

<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">{{ __('Total products') }}</x-slot>
            <p class="text-3xl font-bold">{{ $stats['total'] }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Active offers') }}</x-slot>
            <p class="text-3xl font-bold text-emerald-600">{{ $stats['active'] }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Expired') }}</x-slot>
            <p class="text-3xl font-bold text-red-500">{{ $stats['expired'] }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
