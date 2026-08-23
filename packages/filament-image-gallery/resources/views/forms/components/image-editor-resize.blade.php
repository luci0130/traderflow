<div
    data-image-gallery-resize
    wire:key="image-gallery-resize-{{ md5(serialize([$currentHeight, $currentWidth, $pendingHeight, $pendingWidth])) }}"
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('image-gallery', package: 'turiac/filament-image-gallery') }}"
    x-data="imageGalleryResize({
        currentHeight: @js($currentHeight),
        currentWidth: @js($currentWidth),
        maxHeight: @js($maxHeight),
        maxWidth: @js($maxWidth),
        pendingHeight: @js($pendingHeight),
        pendingWidth: @js($pendingWidth),
    })"
    x-bind:data-applied="isApplied ? 'true' : 'false'"
    x-bind:data-resize-height="resizeHeight"
    x-bind:data-resize-width="resizeWidth"
    class="space-y-3"
>
    <div data-image-gallery-resize-fields class="flex items-center gap-2">
        <x-filament::input.wrapper class="min-w-0 flex-1">
            <x-slot name="prefix">
                <span class="font-semibold text-gray-700 dark:text-gray-200">W</span>
            </x-slot>

            <x-filament::input
                type="number"
                min="1"
                x-bind:max="maximumWidth"
                x-bind:disabled="!isAvailable"
                x-model.number="width"
                x-on:input="updateFromWidth($event.target.value)"
                inputmode="numeric"
                aria-label="{{ __('filament-image-gallery::gallery.fields.resize_width') }}"
                class="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            />

            <x-slot name="suffix">
                <span class="text-gray-500 dark:text-gray-400">px</span>
            </x-slot>
        </x-filament::input.wrapper>

        <div
            class="flex size-8 items-center justify-center text-gray-500 dark:text-gray-400"
            title="{{ __('filament-image-gallery::gallery.editor.aspect_ratio_locked') }}"
            aria-label="{{ __('filament-image-gallery::gallery.editor.aspect_ratio_locked') }}"
        >
            <x-filament::icon icon="heroicon-m-lock-closed" class="size-4" />
        </div>

        <x-filament::input.wrapper class="min-w-0 flex-1">
            <x-slot name="prefix">
                <span class="font-semibold text-gray-700 dark:text-gray-200">H</span>
            </x-slot>

            <x-filament::input
                type="number"
                min="1"
                x-bind:max="maximumHeight"
                x-bind:disabled="!isAvailable"
                x-model.number="height"
                x-on:input="updateFromHeight($event.target.value)"
                inputmode="numeric"
                aria-label="{{ __('filament-image-gallery::gallery.fields.resize_height') }}"
                class="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            />

            <x-slot name="suffix">
                <span class="text-gray-500 dark:text-gray-400">px</span>
            </x-slot>
        </x-filament::input.wrapper>
    </div>

    <x-filament::button
        type="button"
        x-on:click.stop="apply()"
        x-bind:disabled="!canApply"
        class="w-full justify-center"
    >
        {{ __('filament-image-gallery::gallery.actions.apply_resize') }}
    </x-filament::button>

    <div class="hidden" aria-hidden="true">
        {{ $getChildSchema() }}
    </div>
</div>
