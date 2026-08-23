@php
    $dimensions = filled($image['width']) && filled($image['height'])
        ? "{$image['width']} × {$image['height']}"
        : '—';
@endphp

{{--
    The root is a flex column sized to the viewport minus the modal chrome
    (2rem window inset + sticky header + sticky footer + content padding ≈ 16rem),
    so the preview always fills the column without ever forcing the modal to
    scroll. The image stage is the only flexible row: it takes whatever the file
    details and thumbnail strip leave over, and the image scales inside it.
--}}
<div
    data-image-gallery-editor-preview
    @if ($hasFocalPoint)
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('image-gallery-focal-point', package: 'turiac/filament-image-gallery') }}"
        x-data="imageGalleryFocalPointPreview({
            focalX: @js($image['focal_x']),
            focalY: @js($image['focal_y']),
        })"
        x-bind:data-focal-active="isFocalPreviewActive ? 'true' : 'false'"
    @else
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('image-gallery', package: 'turiac/filament-image-gallery') }}"
        x-data="imageGalleryEditorPreview()"
    @endif
    class="flex h-[calc(100dvh-16rem)] min-h-80 flex-col overflow-hidden rounded-2xl bg-gray-50 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10"
>
    <div class="relative min-h-48 flex-1">
        {{--
            `isolate` keeps the badge and the edit button in their own stacking
            context so their z-index cannot paint over the modal's sticky header
            or footer while the modal scrolls. The FilePond editor deliberately
            stays outside this layer: its crop overlay is `fixed z-50` and must
            still escape above the modal window.
        --}}
        <div class="absolute inset-0 isolate">
            @if ($image['is_main'])
                <div class="absolute start-4 top-4 z-20 inline-flex items-center gap-1.5 rounded-lg bg-primary-500 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm">
                    <x-filament::icon icon="heroicon-m-star" class="size-4" />
                    {{ __('filament-image-gallery::gallery.editor.main_image') }}
                </div>
            @endif

            <button
                type="button"
                x-on:click="toggleFileEditor()"
                x-bind:aria-pressed="isEditing"
                class="absolute end-4 top-4 z-30 inline-flex size-10 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow-sm ring-1 ring-gray-950/10 backdrop-blur transition hover:bg-white hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-gray-900/90 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-900 dark:hover:text-primary-400"
                aria-label="{{ __('filament-image-gallery::gallery.actions.edit') }}"
            >
                <x-filament::icon icon="heroicon-m-pencil-square" class="size-5" />
            </button>

            <div
                data-image-gallery-preview-image
                x-bind:data-visible="isEditing ? 'false' : 'true'"
                class="absolute inset-5 flex items-center justify-center overflow-hidden rounded-xl bg-gray-100 transition-opacity data-[visible=false]:pointer-events-none data-[visible=false]:opacity-0 sm:inset-8 dark:bg-gray-950"
            >
                @if ($image['url'])
                    <img
                        src="{{ $image['url'] }}"
                        alt="{{ $image['alt'] }}"
                        @if ($hasFocalPoint)
                            x-bind:class="isFocalPreviewActive ? 'object-cover' : 'object-contain'"
                            x-bind:style="previewImageStyle()"
                        @endif
                        @class([
                            'size-full rounded-xl shadow-sm',
                            'object-contain' => ! $hasFocalPoint,
                        ])
                        decoding="async"
                    />

                    @if ($hasFocalPoint)
                        <div
                            x-show="isFocalPreviewActive"
                            x-cloak
                            x-bind:style="focalMarkerStyle()"
                            data-image-gallery-focal-marker
                            class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-1/2"
                        >
                            <span class="flex size-8 items-center justify-center rounded-full border-2 border-white bg-primary-500/90 shadow-lg ring-2 ring-primary-500/40">
                                <span class="size-1.5 rounded-full bg-white"></span>
                            </span>
                        </div>
                    @endif
                @else
                    <div class="flex flex-col items-center gap-3 text-gray-400 dark:text-gray-500">
                        <x-filament::icon icon="heroicon-o-photo" class="size-16" />
                        <span class="text-sm">{{ __('filament-image-gallery::gallery.ui.preview_unavailable') }}</span>
                    </div>
                @endif
            </div>
        </div>

        <div
            x-ref="fileEditor"
            data-image-gallery-file-editor
            x-bind:data-visible="isEditing ? 'true' : 'false'"
            class="pointer-events-none absolute inset-5 overflow-hidden rounded-xl bg-gray-100 opacity-0 transition-opacity data-[visible=true]:pointer-events-auto data-[visible=true]:opacity-100 sm:inset-8 dark:bg-gray-950 [&_.fi-fo-field-wrp]:h-full [&_.fi-fo-file-upload]:h-full [&_.filepond--item]:h-full [&_.filepond--panel-root]:bg-transparent [&_.filepond--root]:mb-0 [&_.filepond--root]:h-full"
        >
            {{ $getChildSchema() }}
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-3 border-t border-gray-950/10 bg-white/70 px-5 py-4 dark:border-white/10 dark:bg-gray-950/30">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-document" class="size-5" />
        </div>

        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                {{ $image['filename'] }}
            </p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ collect([$image['size'], $dimensions, $image['format']])->filter(fn (string $value): bool => $value !== '—')->join(' · ') ?: '—' }}
            </p>
        </div>
    </div>

    @if ($total > 1)
        <div data-image-gallery-editor-thumbnails class="shrink-0 border-t border-gray-950/10 px-5 py-5 dark:border-white/10">
            <div class="flex snap-x items-center justify-center gap-3 overflow-x-auto pb-1">
                @foreach ($thumbnails as $thumbnail)
                    <div
                        @class([
                            'size-20 shrink-0 snap-center overflow-hidden rounded-xl bg-white p-1 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10 sm:size-24',
                            'ring-2 ring-primary-500' => $thumbnail['is_current'],
                        ])
                    >
                        @if ($thumbnail['url'])
                            <img
                                src="{{ $thumbnail['url'] }}"
                                alt="{{ $thumbnail['alt'] }}"
                                class="size-full rounded-lg object-contain"
                                loading="lazy"
                                decoding="async"
                                fetchpriority="low"
                            />
                        @else
                            <div class="flex size-full items-center justify-center text-gray-400 dark:text-gray-500">
                                <x-filament::icon icon="heroicon-o-photo" class="size-7" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-center text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('filament-image-gallery::gallery.editor.position', ['current' => $current, 'total' => $total]) }}
            </p>
        </div>
    @endif
</div>
