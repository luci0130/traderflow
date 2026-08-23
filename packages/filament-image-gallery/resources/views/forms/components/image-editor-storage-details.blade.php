@php
    $uploaded = collect([$uploadedAt, $uploader])->filter(fn (mixed $value): bool => filled($value) && $value !== '—')->join(' · ') ?: '—';
@endphp

<dl
    data-image-gallery-editor-storage-details
    class="grid grid-cols-1 gap-4 border-t border-gray-950/10 pt-4 dark:border-white/10 sm:grid-cols-2"
>
    <div>
        <dt class="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-cloud" class="size-4" />
            {{ __('filament-image-gallery::gallery.fields.storage') }}
        </dt>
        <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $disk }} · {{ $visibility }}</dd>
    </div>
    <div>
        <dt class="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-calendar" class="size-4" />
            {{ __('filament-image-gallery::gallery.fields.uploaded') }}
        </dt>
        <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $uploaded }}</dd>
    </div>
</dl>
