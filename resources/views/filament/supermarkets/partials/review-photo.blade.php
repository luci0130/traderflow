<x-filament::section>
    <x-slot name="heading">
        {{ $supermarketName ?? __('Photo') }}
        @if (! empty($storeLabel))
            <span style="font-weight: 400; color: #6b7280; font-size: 0.875rem;">— {{ $storeLabel }}</span>
        @endif
    </x-slot>

    @if (! empty($photoUrl))
        <a href="{{ $photoUrl }}" target="_blank" rel="noopener">
            <img
                src="{{ $photoUrl }}"
                alt="{{ __('Price photo') }}"
                style="width: 100%; max-height: 75vh; object-fit: contain; border-radius: 0.5rem;"
            />
        </a>
    @else
        <p style="color: #6b7280; font-size: 0.875rem;">{{ __('Image not available.') }}</p>
    @endif
</x-filament::section>
