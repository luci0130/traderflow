<div class="space-y-3 text-sm" aria-label="{{ __('Malcara contact details') }}">
    <p class="font-semibold">{{ __('Contact Malcara') }}</p>
    <div class="space-y-2">
        <a href="mailto:{{ config('malcara.email') }}" class="flex items-center gap-2 hover:underline">
            <x-filament::icon icon="heroicon-o-envelope" class="h-4 w-4 shrink-0" />
            {{ config('malcara.email') }}
        </a>
        <a href="tel:{{ config('malcara.phone_uri') }}" class="flex items-center gap-2 hover:underline">
            <x-filament::icon icon="heroicon-o-phone" class="h-4 w-4 shrink-0" />
            {{ config('malcara.phone') }}
        </a>
        <p class="flex items-start gap-2">
            <x-filament::icon icon="heroicon-o-map-pin" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ config('malcara.address') }}, {{ __('Romania') }}</span>
        </p>
        <a href="{{ config('malcara.website') }}" class="flex items-center gap-2 hover:underline">
            <x-filament::icon icon="heroicon-o-globe-alt" class="h-4 w-4 shrink-0" />
            www.malcara.com
        </a>
    </div>
</div>
