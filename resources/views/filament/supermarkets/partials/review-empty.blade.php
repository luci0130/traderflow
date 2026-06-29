<x-filament::section>
    <div style="display: flex; flex-direction: column; align-items: center; gap: 0.75rem; padding: 3rem 0; text-align: center;">
        <x-filament::icon icon="heroicon-o-check-circle" style="width: 3rem; height: 3rem; color: #16a34a;" />
        <h3 style="font-size: 1.125rem; font-weight: 600;">{{ __('Nothing left to review') }}</h3>
        <p style="color: #6b7280; font-size: 0.875rem;">{{ __('All uploaded photos have been processed.') }}</p>

        <x-filament::button
            tag="a"
            color="gray"
            icon="heroicon-o-camera"
            href="{{ \App\Modules\Supermarkets\Filament\Pages\UploadPricePhotos::getUrl() }}"
        >
            {{ __('Upload more photos') }}
        </x-filament::button>
    </div>
</x-filament::section>
