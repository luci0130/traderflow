@php
    $dimensions = filled($width) && filled($height) ? "{$width} × {$height} px" : '—';
@endphp

<div data-image-gallery-editor-file-details class="space-y-5">
    <div>
        <p class="text-sm font-medium text-gray-950 dark:text-white">
            {{ __('filament-image-gallery::gallery.fields.details') }}
        </p>

        <dl class="mt-3 space-y-2.5 text-sm">
            <div class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                <x-filament::icon icon="heroicon-o-photo" class="size-4 shrink-0 text-gray-400" />
                <dt class="sr-only">{{ __('filament-image-gallery::gallery.editor.format') }}</dt>
                <dd class="truncate" title="{{ $mimeType }}">{{ $format }}</dd>
            </div>
            <div class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                <x-filament::icon icon="heroicon-o-arrows-pointing-out" class="size-4 shrink-0 text-gray-400" />
                <dt class="sr-only">{{ __('filament-image-gallery::gallery.editor.resolution') }}</dt>
                <dd>{{ $dimensions }}</dd>
            </div>
            <div class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                <x-filament::icon icon="heroicon-o-sparkles" class="size-4 shrink-0 text-gray-400" />
                <dt class="sr-only">{{ __('filament-image-gallery::gallery.editor.file_size') }}</dt>
                <dd>
                    {{ $size }}
                    @if ($savings > 0)
                        <span class="text-gray-400">· {{ __('filament-image-gallery::gallery.editor.savings', ['percentage' => $savings]) }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        <p class="mt-3 truncate text-xs text-gray-400" title="{{ $filename }}">
            {{ $filename }}
        </p>
        @if ($originalSize !== '—' && $originalSize !== $size)
            <p class="mt-1 text-xs text-gray-400">
                {{ __('filament-image-gallery::gallery.editor.original_size', ['size' => $originalSize]) }}
            </p>
        @endif
    </div>

</div>
