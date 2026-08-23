@php
    use Filament\Actions\Action;
    use Illuminate\Support\Js;
    use Illuminate\View\ComponentAttributeBag;

    $fieldWrapperView = $getFieldWrapperView();
    $items = $getItems();
    $addAction = $getAction($getAddActionName());
    $addFromUrlAction = $getAction('addFromUrl');
    $deleteAction = $getAction($getDeleteActionName());
    $reorderAction = $getAction($getReorderActionName());
    $extraItemActions = $getExtraItemActions();
    $isAddable = $isAddable();
    $isDeletable = $isDeletable();
    $isReorderable = $isReorderableWithDragAndDrop();
    $key = $getKey();
    $statePath = $getStatePath();
    $reorderExpression = '$wire.mountAction(\'reorder\', { items: $event.target.sortable.toArray() }, { schemaComponent: '.Js::from($key)->toHtml().' })';
    $tools = $getTools();
    $editableMetadataFields = ['alt_text', 'title'];

    if (in_array('focal-point', $tools, true)) {
        array_push($editableMetadataFields, 'focal_x', 'focal_y');
    }

    if (in_array('resize', $tools, true)) {
        array_push($editableMetadataFields, 'resize_height', 'resize_width');
    }

    $initialItems = [];

    foreach ($items as $itemKey => $item) {
        $itemState = $item->getRawState();

        if ($itemState['_is_uploader'] ?? false) {
            continue;
        }

        $initialItems[$itemKey] = [
            'roles' => array_values((array) ($itemState['roles'] ?? [])),
            'title' => $itemState['title'] ?? null,
        ];
    }
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('image-gallery', package: 'turiac/filament-image-gallery') }}"
        x-data="imageGallery({
            clearMountedActions: () => $wire.set('mountedActions', [], false),
            configuredRoles: @js($getRoles()),
            editableMetadataFields: @js($editableMetadataFields),
            getMountedAction: () => $wire.mountedActions.at(-1) ?? null,
            initialItems: @js($initialItems),
            processingMessage: @js(__('filament-image-gallery::gallery.ui.processing')),
            promotePendingUploads: () => $wire.callSchemaComponentMethod(
                @js($key),
                'promotePendingUploaderFiles',
            ),
            setDeferredState: (path, value) => $wire.set(path, value, false),
            statePath: @js($statePath),
            submitMountedAction: () => $wire.callMountedAction(),
        })"
        x-on:image-gallery-apply.window="applyEditor($event.detail)"
        x-on:open-modal.window="captureEditorState()"
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class(['fi-fo-image-gallery space-y-4'])
        }}
    >
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('filament-image-gallery::gallery.ui.heading') }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('filament-image-gallery::gallery.ui.helper') }}
                </p>
            </div>

            @if ($isAddable)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($addFromUrlAction->isVisible())
                        {{ $addFromUrlAction }}
                    @endif

                    @if ($addAction->isVisible())
                        {{ $addAction }}
                    @endif

                </div>
            @endif
        </div>

        @if (count($items))
            <ul
                x-sortable
                {{
                    (new ComponentAttributeBag)
                        ->merge([
                            'data-sortable-animation-duration' => $getReorderAnimationDuration(),
                            'x-on:end.stop' => $reorderExpression,
                        ], escape: false)
                        ->class(['grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4'])
                }}
            >
                @foreach ($items as $itemKey => $item)
                    @php
                        $itemState = $item->getRawState();
                        $isUploaderItem = (bool) ($itemState['_is_uploader'] ?? false);
                        $roles = (array) ($itemState['roles'] ?? []);
                        $previewUrl = $isUploaderItem ? null : $getPreviewUrl($itemState);
                        $visibleExtraItemActions = $isUploaderItem ? [] : array_filter(
                            $extraItemActions,
                            fn (Action $action): bool => $action(['item' => $itemKey])->isVisible(),
                        );
                        $itemDeleteAction = $isUploaderItem ? null : $deleteAction(['item' => $itemKey]);
                    @endphp

                    @if ($isUploaderItem)
                        @if ($isAddable)
                            <li
                                wire:key="{{ $item->getLivewireKey() }}.uploader"
                                class="fi-fo-image-gallery-uploader aspect-square overflow-hidden rounded-xl border border-dashed border-gray-300 bg-gray-50 transition hover:border-primary-500 dark:border-white/20 dark:bg-white/5 dark:hover:border-primary-400 [&_.fi-fo-file-upload-input-ctn>input]:hidden [&_.fi-fo-file-upload]:h-full [&_.filepond--panel-root]:bg-transparent [&_.filepond--root]:h-full [&_.filepond--root]:mb-0"
                            >
                                {{ $item }}
                            </li>
                        @endif
                    @else
                        <li
                            wire:ignore.self
                            wire:key="{{ $item->getLivewireKey() }}.item"
                            x-sortable-item="{{ $itemKey }}"
                            class="group"
                        >
                            <div
                                data-gallery-item-frame="{{ $itemKey }}"
                                data-main="{{ in_array('main', $roles, true) ? 'true' : 'false' }}"
                                x-bind:data-main="hasRole(@js($itemKey), 'main') ? 'true' : 'false'"
                                class="relative overflow-hidden rounded-xl bg-white shadow-sm ring-1 transition data-[main=false]:ring-gray-950/10 data-[main=true]:ring-2 data-[main=true]:ring-primary-500 dark:bg-gray-900 dark:data-[main=false]:ring-white/10"
                            >
                                <div class="relative aspect-square overflow-hidden bg-gray-100 dark:bg-white/5">
                                    @if ($previewUrl)
                                        <img
                                            src="{{ $previewUrl }}"
                                            alt="{{ $itemState['alt_text'] ?? $itemState['title'] ?? '' }}"
                                            class="h-full w-full object-contain"
                                            loading="lazy"
                                        />
                                    @else
                                        <div class="flex h-full items-center justify-center text-gray-400 dark:text-gray-500">
                                            <x-filament::icon icon="heroicon-o-photo" class="h-10 w-10" />
                                        </div>
                                    @endif

                                    <button
                                        type="button"
                                        class="absolute inset-0 z-10 cursor-pointer bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-inset"
                                        x-on:click.stop="$wire.mountAction('editImage', { item: @js($itemKey) }, { schemaComponent: @js($key) })"
                                        aria-label="{{ __('filament-image-gallery::gallery.actions.edit') }}"
                                    ></button>

                                    <div
                                        x-show="hasRole(@js($itemKey), 'main')"
                                        @if (! in_array('main', $roles, true)) x-cloak @endif
                                        class="absolute start-2 top-2 z-20 rounded-lg bg-primary-500 px-2 py-1 text-xs font-semibold text-white shadow-sm"
                                    >
                                        {{ __('filament-image-gallery::gallery.ui.primary') }}
                                    </div>

                                    <div class="absolute inset-x-0 bottom-0 z-20 flex items-center justify-end gap-1 bg-gradient-to-t from-black/70 to-transparent p-2 pt-8 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                                        @if ($isReorderable && $reorderAction->isVisible())
                                            {{ $reorderAction(['item' => $itemKey])->extraAttributes(['x-sortable-handle' => true], merge: true) }}
                                        @endif

                                        @foreach ($visibleExtraItemActions as $extraItemAction)
                                            {{ $extraItemAction(['item' => $itemKey]) }}
                                        @endforeach

                                        @if ($isDeletable && $itemDeleteAction->isVisible())
                                            {{ $itemDeleteAction }}
                                        @endif
                                    </div>
                                </div>

                                <div class="min-w-0 p-3">
                                    <p
                                        class="truncate text-sm font-medium text-gray-950 dark:text-white"
                                        x-text="items[@js($itemKey)]?.title || @js(($itemState['title'] ?? null) ?: ($itemState['original_filename'] ?? null) ?: __('filament-image-gallery::gallery.ui.untitled'))"
                                    >
                                        {{ ($itemState['title'] ?? null) ?: ($itemState['original_filename'] ?? null) ?: __('filament-image-gallery::gallery.ui.untitled') }}
                                    </p>

                                    <p
                                        x-show="roleSummary(@js($itemKey))"
                                        x-text="roleSummary(@js($itemKey))"
                                        @if ($roles === []) x-cloak @endif
                                        class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ collect($roles)->map(fn (string $role): string => $getRoles()[$role] ?? $role)->join(' · ') }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
        @else
            <div class="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/20">
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('filament-image-gallery::gallery.ui.empty_heading') }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('filament-image-gallery::gallery.ui.empty_description') }}
                </p>
            </div>
        @endif

        @if ($getRealItemsCount() > 1)
            <p class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-arrows-up-down" class="h-4 w-4" />
                {{ __('filament-image-gallery::gallery.ui.reorder') }}
            </p>
        @endif
    </div>
</x-dynamic-component>
