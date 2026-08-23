<?php

namespace Turiac\FilamentImageGallery\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use Turiac\FilamentImageGallery\Contracts\GalleryRepository;
use Turiac\FilamentImageGallery\FilamentImageGalleryPlugin;
use Turiac\FilamentImageGallery\Models\GalleryImage;
use Turiac\FilamentImageGallery\Services\ImageProcessor;
use Turiac\FilamentImageGallery\Services\RemoteImageDownloader;
use Turiac\FilamentImageGallery\Support\PendingUploadSigner;

class ImageGallery extends Repeater
{
    protected string $view = 'filament-image-gallery::forms.components.image-gallery';

    protected string|Closure|null $collection = null;

    protected string|Closure|null $disk = null;

    protected string|Closure|null $directory = null;

    protected string|Closure|null $visibility = null;

    protected bool|Closure|null $optimizeImages = null;

    protected int|Closure|null $maxParallelUploads = null;

    /**
     * @var array<string, string> | Closure | null
     */
    protected array|Closure|null $roles = null;

    /**
     * @var array<string> | Closure | null
     */
    protected array|Closure|null $defaultRoles = null;

    /**
     * @var array<string> | Closure | null
     */
    protected array|Closure|null $tools = null;

    /** @var array<int, GalleryImage> | null */
    private ?array $persistedImages = null;

    /** @var array<string, string> | null */
    private ?array $resolvedRoles = null;

    /** @var array<int, string> | null */
    private ?array $resolvedTools = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultItems(0);
        $this->maxItems(fn (): int => $this->getPlugin()?->getMaxImages() ?? (int) config('filament-image-gallery.max_images', 10));
        $this->default(fn (): array => [$this->uploaderItemState()]);
        $this->reorderable();
        $this->reorderableWithButtons(false);
        $this->reorderAction(fn (Action $action): Action => $action
            ->action(function (array $arguments, ImageGallery $component): void {
                $component->rawState($component->reorderItems(
                    $component->getRawState() ?? [],
                    Arr::wrap($arguments['items'] ?? []),
                ));
                $component->callAfterStateUpdated();

                if ($component->shouldPartiallyRenderAfterActionsCalled()) {
                    $component->partiallyRender();
                }
            }));
        $this->deleteAction(fn (Action $action): Action => $action
            ->after(function (ImageGallery $component): void {
                $component->rawState($component->normalizeRoles($component->getRawState() ?? []));
            }));
        $this->addActionLabel(__('filament-image-gallery::gallery.actions.add_images'));
        $this->dehydrated(false);

        $this->schema(fn (ImageGallery $component): array => $component->imageItemSchema());

        $this->loadStateFromRelationshipsUsing(function (ImageGallery $component): void {
            $record = $component->getRecord();

            if (! $record instanceof Model || ! $record->exists) {
                return;
            }

            $component->rawState($component->reconcileLoadedState(
                loadedItems: app(GalleryRepository::class)->stateFor($record, $component->getCollection()),
                currentItems: $component->getRawState() ?? [],
            ));
        });

        $this->saveRelationshipsUsing(function (ImageGallery $component): void {
            $record = $component->getRecord();

            if (! $record instanceof Model || ! $record->exists) {
                return;
            }

            $state = [];

            foreach ($component->getItems() as $key => $item) {
                $itemState = $item->getState(shouldCallHooksBefore: false);

                if ($itemState['_is_uploader'] ?? false) {
                    continue;
                }

                $state[$key] = $itemState;
            }

            $state = $component->normalizeRoles($component->withUploaderItem(
                $state,
                $component->uploaderItemKey($component->getRawState() ?? []),
            ));
            [$state, $generatedFiles] = $component->resizeStoredImages($state);

            try {
                app(GalleryRepository::class)->persist(
                    owner: $record,
                    collection: $component->getCollection(),
                    state: $state,
                    configuredRoles: $component->getRoles(),
                    disk: $component->getDisk(),
                    visibility: $component->getVisibility(),
                    directory: $component->getDirectory(),
                    statePath: $component->getStatePath(),
                );
            } catch (Throwable $exception) {
                foreach ($generatedFiles as [$disk, $path]) {
                    try {
                        Storage::disk($disk)->delete($path);
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }

                throw $exception;
            }
        });

        $this->registerActions([
            fn (ImageGallery $component): Action => $component->getAddFromUrlAction(),
            fn (ImageGallery $component): Action => $component->getEditImageAction(),
        ]);
    }

    public function hydrateItems(): void
    {
        if (
            is_array($this->hydratedDefaultState)
            && $this->shouldMergeHydratedDefaultStateWithItemsStateAfterStateHydrated
        ) {
            $this->mergeHydratedDefaultStateWithItemsState();
        }

        if (is_array($this->hydratedDefaultState)) {
            return;
        }

        $items = [];
        $simpleField = $this->getSimpleField();

        foreach ($this->getRawState() ?? [] as $itemKey => $itemData) {
            if ($simpleField) {
                $itemData = [$simpleField->getName() => $itemData];
            }

            $items[(string) $itemKey] = $itemData;
        }

        $this->rawState($items);
    }

    public function getAddAction(): Action
    {
        $remainingImages = max(1, $this->getMaximumImages() - $this->getRealItemsCount());

        return Action::make($this->getAddActionName())
            ->label(__('filament-image-gallery::gallery.actions.add_images'))
            ->icon(Heroicon::ArrowUpTray)
            ->color('primary')
            ->schema([
                FileUpload::make('uploads')
                    ->label(__('filament-image-gallery::gallery.fields.images'))
                    ->multiple()
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxFiles($remainingImages)
                    ->maxParallelUploads($this->getMaximumParallelUploads())
                    ->maxSize(fn (): int => (int) config('filament-image-gallery.max_size', 10240))
                    ->rule(Rule::dimensions()
                        ->maxWidth((int) config('filament-image-gallery.max_width', 5000))
                        ->maxHeight((int) config('filament-image-gallery.max_height', 5000)))
                    ->extraInputAttributes([
                        'data-allow-minimum-upload-duration' => config('filament-image-gallery.allow_minimum_upload_duration', false) ? 'true' : 'false',
                    ])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, ImageGallery $component): void {
                foreach (Arr::wrap($data['uploads'] ?? []) as $file) {
                    if ($file instanceof TemporaryUploadedFile) {
                        $component->appendUpload($file);
                    }
                }
            })
            ->modalWidth(Width::Large)
            ->visible(fn (ImageGallery $component): bool => $component->isAddable());
    }

    public function getAddFromUrlAction(): Action
    {
        return Action::make('addFromUrl')
            ->label(__('filament-image-gallery::gallery.actions.add_from_url'))
            ->icon(Heroicon::Link)
            ->color('gray')
            ->schema([
                TextInput::make('url')
                    ->label(__('filament-image-gallery::gallery.fields.url'))
                    ->url()
                    ->required(),
            ])
            ->action(function (array $data, ImageGallery $component): void {
                if (! $component->isAddable()) {
                    return;
                }

                $file = app(RemoteImageDownloader::class)->download(
                    $data['url'],
                    (int) config('filament-image-gallery.max_size', 10240) * 1024,
                );

                $component->appendUpload($file);
            })
            ->modalWidth(Width::Large)
            ->visible(fn (ImageGallery $component): bool => $component->isAddable());
    }

    public function getEditImageAction(): Action
    {
        return Action::make('editImage')
            ->label(__('filament-image-gallery::gallery.actions.edit'))
            ->icon(Heroicon::PencilSquare)
            ->fillForm(fn (array $arguments, ImageGallery $component): array => $component->editorState(
                is_string($arguments['item'] ?? null) ? $arguments['item'] : '',
            ))
            ->schema(fn (array $arguments, ImageGallery $component): array => $component->editorSchema($arguments['item'] ?? ''))
            ->action(function (array $data, array $arguments, ImageGallery $component, Action $action, LivewireComponent $livewire): void {
                $itemKey = $arguments['item'] ?? null;
                $items = $component->getRawState();

                if (
                    $component->isDisabled()
                    || ! is_string($itemKey)
                    || ! is_array($items[$itemKey] ?? null)
                    || ($items[$itemKey]['_is_uploader'] ?? false)
                ) {
                    $action->halt();

                    return;
                }

                $previousPath = $items[$itemKey]['path'] ?? null;

                try {
                    $data = $component->applyReplacementToEditorData($data);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                if ($component->isTemporaryPreviewPath($data['path'] ?? null, $previousPath)) {
                    $data['path'] = $previousPath;
                }

                if ($component->fileIdentity($data['path'] ?? null) !== $component->fileIdentity($previousPath)) {
                    $data['focal_x'] = null;
                    $data['focal_y'] = null;
                }

                if (blank($data['alt_text'] ?? null)) {
                    $data['alt_text'] = $data['title'] ?? null;
                }

                $selectedRoles = Arr::wrap($data['roles'] ?? []);
                $transferredRoles = [];

                foreach ($items as $key => &$item) {
                    if ($key === $itemKey) {
                        continue;
                    }

                    $oldRoles = Arr::wrap($item['roles'] ?? []);
                    $item['roles'] = array_values(array_diff($oldRoles, $selectedRoles));
                    $transferredRoles = [...$transferredRoles, ...array_intersect($oldRoles, $selectedRoles)];
                }
                unset($item);

                $items[$itemKey] = [...$items[$itemKey], ...$data];
                $component->rawState($component->normalizeRoles($items));
                $component->callAfterStateUpdated();

                if ($transferredRoles !== []) {
                    Notification::make()
                        ->title(__('filament-image-gallery::gallery.notifications.roles_transferred'))
                        ->success()
                        ->send();
                }

                if (filled($arguments['navigate'] ?? null)) {
                    $nextItemKey = $component->adjacentItemKey($itemKey, $arguments['navigate']);

                    if ($nextItemKey !== null) {
                        $livewire->replaceMountedAction(
                            'editImage',
                            ['item' => $nextItemKey],
                            ['schemaComponent' => $component->getKey()],
                        );
                        $action->halt();
                    }
                }
            })
            ->modalSubmitAction(fn (Action $action, array $arguments, ImageGallery $component): Action => $action
                ->submit(null)
                ->actionJs($component->applyEditorActionJs($arguments['item'] ?? '')))
            ->extraModalFooterActions(fn (Action $action, array $arguments, ImageGallery $component): array => [
                ...(isset($arguments['item']) ? array_map(
                    fn (Action $imageAction): Action => (clone $imageAction)(['item' => $arguments['item']]),
                    $component->getExtraItemActions(),
                ) : []),
                $action->makeModalSubmitAction('previousImage', ['navigate' => 'previous'])
                    ->label(__('filament-image-gallery::gallery.actions.previous'))
                    ->icon(Heroicon::ChevronLeft)
                    ->disabled($component->adjacentItemKey($arguments['item'] ?? '', 'previous') === null)
                    ->color('gray'),
                $action->makeModalSubmitAction('nextImage', ['navigate' => 'next'])
                    ->label(__('filament-image-gallery::gallery.actions.next'))
                    ->icon(Heroicon::ChevronRight)
                    ->iconPosition('after')
                    ->disabled($component->adjacentItemKey($arguments['item'] ?? '', 'next') === null)
                    ->color('gray'),
            ])
            ->modalHeading(fn (array $arguments, ImageGallery $component): string => $component->editorHeading($arguments['item']))
            ->modalSubmitActionLabel(__('filament-image-gallery::gallery.actions.apply'))
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'fi-image-gallery-editor-modal'])
            ->modalWidth(Width::SevenExtraLarge)
            ->visible(fn (ImageGallery $component): bool => ! $component->isDisabled());
    }

    private function applyEditorActionJs(string $itemKey): string
    {
        $initialPathIdentity = Js::from(
            $this->fileIdentity($this->editorState($itemKey)['path'] ?? null),
        )->toHtml();
        $statePath = Js::from($this->getStatePath())->toHtml();
        $itemKey = Js::from($itemKey)->toHtml();

        return <<<JS
        const modal = \$el.closest('[data-fi-modal-id]');

        \$dispatch('image-gallery-apply', {
            data: \$wire.mountedActions.at(-1)?.data ?? {},
            initialPathIdentity: {$initialPathIdentity},
            itemKey: {$itemKey},
            modalId: modal?.dataset.fiModalId ?? null,
            statePath: {$statePath},
        });
        JS;
    }

    /**
     * @return array<int, mixed>
     */
    public function editorSchema(string $itemKey): array
    {
        return [
            Grid::make(12)
                ->schema([
                    SchemaView::make('filament-image-gallery::forms.components.image-editor-preview')
                        ->viewData(fn (): array => $this->editorPreviewData($itemKey))
                        ->schema([
                            $this->makeFileUpload(editor: true),
                        ])
                        ->columnSpan(['default' => 12, 'lg' => 7]),
                    Grid::make(1)
                        ->schema($this->toolSchema())
                        ->extraAttributes([
                            'class' => 'fi-image-gallery-editor-tools',
                            'x-data' => 'imageGalleryToolAccordion()',
                            'x-load' => '',
                            'x-load-src' => FilamentAsset::getAlpineComponentSrc(
                                'image-gallery',
                                package: 'turiac/filament-image-gallery',
                            ),
                        ])
                        ->columnSpan(['default' => 12, 'lg' => 5]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function imageItemSchema(): array
    {
        return [
            $this->makeFileUpload(editor: false),
            Hidden::make('_is_uploader'),
            Hidden::make('_id')->rules(['nullable', 'integer', 'min:1']),
            Hidden::make('_upload_token')->rules(['nullable', 'string', 'max:2048']),
            Hidden::make('original_filename')->rules(['nullable', 'string', 'max:255']),
            Hidden::make('title')->rules(['nullable', 'string', 'max:255']),
            Hidden::make('alt_text')->rules(['nullable', 'string', 'max:1000']),
            Hidden::make('roles')
                ->rules(['array'])
                ->rule(function (): Closure {
                    $configuredRoles = array_keys($this->getRoles());

                    return static function (string $attribute, mixed $value, Closure $fail) use ($configuredRoles): void {
                        $unknownRoles = array_diff(Arr::wrap($value), $configuredRoles);

                        if ($unknownRoles !== []) {
                            $fail(__('filament-image-gallery::gallery.validation.unknown_role', [
                                'role' => (string) reset($unknownRoles),
                            ]));
                        }
                    };
                }),
            Hidden::make('mime_type')->rules(['nullable', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])]),
            Hidden::make('size')->rules(['nullable', 'integer', 'min:1']),
            Hidden::make('original_size')->rules(['nullable', 'integer', 'min:1']),
            Hidden::make('width')->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_width', 5000)]),
            Hidden::make('height')->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_height', 5000)]),
            Hidden::make('focal_x'),
            Hidden::make('focal_y'),
            Hidden::make('disk'),
            Hidden::make('visibility'),
            Hidden::make('uploaded_at'),
            Hidden::make('uploader'),
            Hidden::make('resize_width')
                ->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_width', 5000)]),
            Hidden::make('resize_height')
                ->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_height', 5000)]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function toolSchema(): array
    {
        /** @var array<string, Closure(): Section> $schemaFactories */
        $schemaFactories = [
            'information' => fn (): Section => Section::make(__('filament-image-gallery::gallery.tools.information'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('filament-image-gallery::gallery.fields.title'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('alt_text')
                        ->label(__('filament-image-gallery::gallery.fields.alt_text'))
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            ToggleButtons::make('roles')
                                ->label(__('filament-image-gallery::gallery.fields.roles'))
                                ->options($this->getRoles())
                                ->icons(array_fill_keys(array_keys($this->getRoles()), Heroicon::CheckCircle))
                                ->colors([
                                    ...array_fill_keys(array_keys($this->getRoles()), 'gray'),
                                    'main' => 'primary',
                                ])
                                ->multiple()
                                ->columns(2),
                            SchemaView::make('filament-image-gallery::forms.components.image-editor-file-details')
                                ->viewData(fn (Get $get): array => $this->editorFileDetails($get)),
                        ])
                        ->columnSpanFull(),
                    SchemaView::make('filament-image-gallery::forms.components.image-editor-storage-details')
                        ->viewData(fn (Get $get): array => $this->editorFileDetails($get))
                        ->columnSpanFull(),
                ])
                ->icon(Heroicon::InformationCircle)
                ->secondary()
                ->compact()
                ->collapsible(),
            'focal-point' => fn (): Section => Section::make(__('filament-image-gallery::gallery.tools.focal_point'))
                ->description(__('filament-image-gallery::gallery.tools.focal_point_helper'))
                ->schema([
                    Slider::make('focal_x')
                        ->label(__('filament-image-gallery::gallery.fields.focal_x'))
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.1)
                        ->extraAttributes(['data-image-gallery-focal-axis' => 'x'])
                        ->default(50),
                    Slider::make('focal_y')
                        ->label(__('filament-image-gallery::gallery.fields.focal_y'))
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.1)
                        ->extraAttributes(['data-image-gallery-focal-axis' => 'y'])
                        ->default(50),
                ])
                ->icon(Heroicon::ViewfinderCircle)
                ->secondary()
                ->compact()
                ->collapsible()
                ->collapsed(),
            'crop' => fn (): Section => Section::make(__('filament-image-gallery::gallery.tools.crop'))
                ->schema([
                    Placeholder::make('crop_help')
                        ->hiddenLabel()
                        ->content(__('filament-image-gallery::gallery.tools.crop_helper')),
                ])
                ->extraAttributes(['data-image-gallery-crop-trigger' => ''])
                ->icon(Heroicon::ArrowsPointingOut)
                ->secondary()
                ->compact()
                ->collapsible()
                ->collapsed(),
            'resize' => fn (): Section => Section::make(__('filament-image-gallery::gallery.tools.resize'))
                ->schema([
                    SchemaView::make('filament-image-gallery::forms.components.image-editor-resize')
                        ->viewData(fn (Get $get): array => [
                            'currentHeight' => filled($get('height')) ? (int) $get('height') : null,
                            'currentWidth' => filled($get('width')) ? (int) $get('width') : null,
                            'maxHeight' => (int) config('filament-image-gallery.max_height', 5000),
                            'maxWidth' => (int) config('filament-image-gallery.max_width', 5000),
                            'pendingHeight' => filled($get('resize_height')) ? (int) $get('resize_height') : null,
                            'pendingWidth' => filled($get('resize_width')) ? (int) $get('resize_width') : null,
                        ])
                        ->schema([
                            Hidden::make('resize_width')
                                ->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_width', 5000)])
                                ->extraAttributes(['data-image-gallery-resize-state' => 'width']),
                            Hidden::make('resize_height')
                                ->rules(['nullable', 'integer', 'min:1', 'max:'.(int) config('filament-image-gallery.max_height', 5000)])
                                ->extraAttributes(['data-image-gallery-resize-state' => 'height']),
                        ])
                        ->columnSpanFull(),
                ])
                ->icon(Heroicon::ArrowsPointingOut)
                ->secondary()
                ->compact()
                ->collapsible()
                ->collapsed(),
            'replace' => fn (): Section => Section::make(__('filament-image-gallery::gallery.tools.replace'))
                ->schema([
                    FileUpload::make('replacement_upload')
                        ->label(__('filament-image-gallery::gallery.fields.replacement_image'))
                        ->helperText(__('filament-image-gallery::gallery.tools.replace_upload_helper'))
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->maxSize((int) config('filament-image-gallery.max_size', 10240))
                        ->rule(Rule::dimensions()
                            ->maxWidth((int) config('filament-image-gallery.max_width', 5000))
                            ->maxHeight((int) config('filament-image-gallery.max_height', 5000)))
                        ->prohibits('replacement_url')
                        ->storeFiles(false)
                        ->panelAspectRatio('2:1')
                        ->panelLayout('integrated')
                        ->imagePreviewHeight('10rem')
                        ->openable(false)
                        ->downloadable(false)
                        ->extraFieldWrapperAttributes(['data-image-gallery-replacement-upload' => ''])
                        ->extraInputAttributes([
                            'data-allow-minimum-upload-duration' => config('filament-image-gallery.allow_minimum_upload_duration', false) ? 'true' : 'false',
                        ]),
                    TextInput::make('replacement_url')
                        ->label(__('filament-image-gallery::gallery.actions.add_from_url'))
                        ->helperText(__('filament-image-gallery::gallery.tools.replace_url_helper'))
                        ->placeholder('https://example.com/image.jpg')
                        ->prefixIcon(Heroicon::Link)
                        ->url()
                        ->rules(['starts_with:https://'])
                        ->maxLength(2048)
                        ->prohibits('replacement_upload')
                        ->extraFieldWrapperAttributes(['data-image-gallery-replacement-url' => '']),
                ])
                ->icon(Heroicon::ArrowPath)
                ->secondary()
                ->compact()
                ->collapsible()
                ->collapsed(),
        ];

        return collect($this->getTools())
            ->filter(fn (string $tool): bool => array_key_exists($tool, $schemaFactories))
            ->map(fn (string $tool): Section => $schemaFactories[$tool]()
                // A stable, per-component id lets the accordion collapse the other
                // tools through Filament's `collapse-section` window event.
                ->id($this->toolSectionId($tool)))
            ->values()
            ->all();
    }

    private function toolSectionId(string $tool): string
    {
        return 'image-gallery-tool-'.Str::slug($this->getKey().'-'.$tool);
    }

    private function makeFileUpload(bool $editor): FileUpload
    {
        $upload = FileUpload::make('path')
            ->label($editor ? __('filament-image-gallery::gallery.fields.image') : '')
            ->hiddenLabel(! $editor)
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->disk($this->getDisk())
            ->visibility($this->getVisibility())
            ->directory($this->getDirectory())
            ->maxSize((int) config('filament-image-gallery.max_size', 10240))
            ->rule(Rule::dimensions()
                ->maxWidth((int) config('filament-image-gallery.max_width', 5000))
                ->maxHeight((int) config('filament-image-gallery.max_height', 5000)));

        if ($editor) {
            return $upload
                ->hiddenLabel()
                ->storeFiles(false)
                ->fetchFileInformation(false)
                ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames, Get $get): ?array {
                    if (! str_starts_with($file, 'https://') && ! str_starts_with($file, 'http://')) {
                        return $component->getUploadedFile($file, $storedFileNames);
                    }

                    return [
                        'name' => $get('original_filename') ?: basename(parse_url($file, PHP_URL_PATH) ?: $file),
                        'size' => (int) ($get('size') ?: 0),
                        'type' => $get('mime_type') ?: 'image/*',
                        'url' => $file,
                    ];
                })
                ->imageEditor(in_array('crop', $this->getTools(), true) || in_array('resize', $this->getTools(), true))
                ->imageEditorAspectRatios([null, '1:1', '3:2', '5:4', '7:5', '16:9'])
                ->imageResizeUpscale(false)
                ->panelAspectRatio('1:1')
                ->panelLayout('integrated')
                ->imagePreviewHeight('100%')
                ->deletable(in_array('replace', $this->getTools(), true))
                ->openable();
        }

        return $upload
            ->multiple(fn (Get $get): bool => (bool) $get('_is_uploader'))
            ->maxFiles(fn (): int => max(1, $this->getMaximumImages() - $this->getRealItemsCount()))
            ->maxParallelUploads($this->getMaximumParallelUploads())
            ->extraInputAttributes([
                'data-allow-minimum-upload-duration' => config('filament-image-gallery.allow_minimum_upload_duration', false) ? 'true' : 'false',
            ])
            ->panelAspectRatio(fn (Get $get): ?string => $get('_is_uploader') ? '1:1' : null)
            ->panelLayout(fn (Get $get): string => $get('_is_uploader') ? 'integrated' : 'compact')
            ->previewable(fn (Get $get): bool => (bool) $get('_is_uploader'))
            ->fetchFileInformation(false)
            ->skipRenderAfterStateUpdated()
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, Set $set, Get $get): string {
                $processed = app(ImageProcessor::class)->process(
                    file: $file,
                    disk: $this->getDisk(),
                    directory: $this->getDirectory(),
                    visibility: $this->getVisibility(),
                    optimize: $this->shouldOptimizeImages(),
                    quality: (int) config('filament-image-gallery.webp_quality', 82),
                    maxSizeKilobytes: (int) config('filament-image-gallery.max_size', 10240),
                    maxWidth: (int) config('filament-image-gallery.max_width', 5000),
                    maxHeight: (int) config('filament-image-gallery.max_height', 5000),
                    targetWidth: filled($get('resize_width')) ? (int) $get('resize_width') : null,
                    targetHeight: filled($get('resize_height')) ? (int) $get('resize_height') : null,
                );

                $set('original_filename', $processed->originalFilename);
                $set('mime_type', $processed->mimeType);
                $set('size', $processed->size);
                $set('original_size', $processed->originalSize);
                $set('width', $processed->width);
                $set('height', $processed->height);
                $set('_upload_token', app(PendingUploadSigner::class)->sign($this->getDisk(), $processed->path));

                return $processed->path;
            })
            ->deletable(false)
            ->openable(false)
            ->downloadable(false)
            ->imagePreviewHeight('100%');
    }

    public function appendUpload(TemporaryUploadedFile $file): void
    {
        if (! $this->isAddable()) {
            return;
        }

        $this->appendFiles([$file]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyReplacementToEditorData(array $data): array
    {
        $replacement = collect(Arr::wrap($data['replacement_upload'] ?? null))
            ->first(fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);
        $replacementUrl = trim((string) ($data['replacement_url'] ?? ''));

        unset($data['replacement_upload'], $data['replacement_url']);

        if (! $replacement instanceof TemporaryUploadedFile && $replacementUrl !== '') {
            $replacement = app(RemoteImageDownloader::class)->download(
                $replacementUrl,
                (int) config('filament-image-gallery.max_size', 10240) * 1024,
            );
        }

        if (! $replacement instanceof TemporaryUploadedFile) {
            return $data;
        }

        $dimensions = getimagesize($replacement->getRealPath()) ?: [null, null];

        return [
            ...$data,
            'path' => [$replacement],
            'original_filename' => $replacement->getClientOriginalName(),
            'mime_type' => $replacement->getMimeType(),
            'size' => $replacement->getSize(),
            'original_size' => $replacement->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'focal_x' => null,
            'focal_y' => null,
            'resize_width' => null,
            'resize_height' => null,
        ];
    }

    public function promoteUploaderFiles(mixed $state): void
    {
        $files = collect(Arr::wrap($state))
            ->filter(fn (mixed $file): bool => $file instanceof TemporaryUploadedFile)
            ->take(max(0, $this->getMaximumImages() - $this->getRealItemsCount()))
            ->values()
            ->all();

        if ($files === []) {
            return;
        }

        $this->appendFiles($files);
    }

    #[ExposedLivewireMethod]
    public function promotePendingUploaderFiles(): void
    {
        if (! $this->isAddable()) {
            return;
        }

        foreach ($this->getRawState() ?? [] as $item) {
            if (! ($item['_is_uploader'] ?? false)) {
                continue;
            }

            $this->promoteUploaderFiles($item['path'] ?? []);
            $this->partiallyRender();

            return;
        }
    }

    /**
     * @param  array<int, TemporaryUploadedFile>  $files
     */
    private function appendFiles(array $files): void
    {
        if (! $this->isAddable()) {
            return;
        }

        $items = $this->realItems($this->getRawState() ?? []);
        $isFirst = $items === [];

        foreach ($files as $file) {
            if (count($items) >= $this->getMaximumImages()) {
                break;
            }

            $key = (string) Str::uuid();
            $title = Str::of($file->getClientOriginalName())->beforeLast('.')->replace(['_', '-'], ' ')->squish()->value();
            $dimensions = getimagesize($file->getRealPath()) ?: [null, null];

            $items[$key] = [
                'path' => [$file],
                'original_filename' => $file->getClientOriginalName(),
                'title' => $title,
                'alt_text' => $title,
                'roles' => $isFirst ? $this->getDefaultRoles() : [],
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'original_size' => $file->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'focal_x' => null,
                'focal_y' => null,
                '_upload_token' => null,
                '_is_uploader' => false,
            ];

            $isFirst = false;
        }

        $this->rawState($this->normalizeRoles($items));
        $this->callAfterStateUpdated();
    }

    /**
     * @return array<string, mixed>
     */
    public function editorState(string $itemKey): array
    {
        $state = $this->getRawState()[$itemKey] ?? [];

        if (! is_array($state) || ($state['_is_uploader'] ?? false)) {
            return [];
        }

        foreach (Arr::wrap($state['path'] ?? null) as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $state['path'] = [$file->temporaryUrl()];

            break;
        }

        return $state;
    }

    /**
     * @param  array<string, array<string, mixed>>  $state
     * @return array{array<string, array<string, mixed>>, array<int, array{string, string}>}
     */
    private function resizeStoredImages(array $state): array
    {
        $generatedFiles = [];
        $persistedImages = $this->persistedImagesById();

        try {
            foreach ($state as &$item) {
                $targetWidth = filled($item['resize_width'] ?? null) ? (int) $item['resize_width'] : null;
                $targetHeight = filled($item['resize_height'] ?? null) ? (int) $item['resize_height'] : null;

                if ($targetWidth === null && $targetHeight === null) {
                    continue;
                }

                if (blank($item['_id'] ?? null)) {
                    continue;
                }

                $persistedImage = $persistedImages[(int) $item['_id']] ?? null;

                $path = $this->fileIdentity($item['path'] ?? null);

                if (! is_string($path) || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    continue;
                }

                if (! $persistedImage instanceof GalleryImage || $persistedImage->path !== $path) {
                    throw ValidationException::withMessages([
                        $this->getStatePath() => __('filament-image-gallery::gallery.validation.unknown_image'),
                    ]);
                }

                $disk = $persistedImage->disk;
                $visibility = $persistedImage->visibility;
                $processed = app(ImageProcessor::class)->processStored(
                    sourcePath: $path,
                    disk: $disk,
                    directory: $this->getDirectory(),
                    visibility: $visibility,
                    optimize: $this->shouldOptimizeImages(),
                    quality: (int) config('filament-image-gallery.webp_quality', 82),
                    maxSizeKilobytes: (int) config('filament-image-gallery.max_size', 10240),
                    maxWidth: (int) config('filament-image-gallery.max_width', 5000),
                    maxHeight: (int) config('filament-image-gallery.max_height', 5000),
                    targetWidth: $targetWidth,
                    targetHeight: $targetHeight,
                );

                $generatedFiles[] = [$disk, $processed->path];
                $item['path'] = [$processed->path];
                $item['original_filename'] = $item['original_filename'] ?? $processed->originalFilename;
                $item['mime_type'] = $processed->mimeType;
                $item['size'] = $processed->size;
                $item['original_size'] = $processed->originalSize;
                $item['width'] = $processed->width;
                $item['height'] = $processed->height;
                $item['_upload_token'] = app(PendingUploadSigner::class)->sign($disk, $processed->path);
                $item['resize_width'] = null;
                $item['resize_height'] = null;
            }
            unset($item);
        } catch (Throwable $exception) {
            foreach ($generatedFiles as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return [$state, $generatedFiles];
    }

    public function isTemporaryPreviewPath(mixed $submittedPath, mixed $originalPath): bool
    {
        $submittedIdentity = $this->fileIdentity($submittedPath);

        if (! is_string($submittedIdentity) || ! (str_starts_with($submittedIdentity, 'http://') || str_starts_with($submittedIdentity, 'https://'))) {
            return false;
        }

        foreach (Arr::wrap($originalPath) as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    public function withUploaderItem(array $items, ?string $uploaderKey = null): array
    {
        $uploaderKey ??= $this->uploaderItemKey($items);
        $items = $this->realItems($items);

        foreach ($items as &$item) {
            $item['path'] = array_values(Arr::wrap($item['path'] ?? []));
        }
        unset($item);

        if (count($items) < $this->getMaximumImages()) {
            $items[$uploaderKey ?? (string) Str::uuid()] = $this->uploaderItemState();
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, mixed>>  $loadedItems
     * @param  array<string, array<string, mixed>>  $currentItems
     * @return array<string, array<string, mixed>>
     */
    private function reconcileLoadedState(array $loadedItems, array $currentItems): array
    {
        $currentRealItems = $this->realItems($currentItems);
        $currentKeys = array_keys($currentRealItems);
        $usedCurrentKeys = [];
        $reconciledItems = [];
        $keysById = [];
        $keysByPath = [];

        foreach ($currentRealItems as $currentKey => $currentItem) {
            $currentKey = (string) $currentKey;

            if (filled($currentItem['_id'] ?? null)) {
                $keysById[(string) $currentItem['_id']] ??= $currentKey;
            }

            if (($currentPath = $this->fileIdentity($currentItem['path'] ?? null)) !== null) {
                $keysByPath[$currentPath] ??= $currentKey;
            }
        }

        $fallbackIndex = 0;

        foreach ($loadedItems as $loadedKey => $loadedItem) {
            $loadedPath = $this->fileIdentity($loadedItem['path'] ?? null);
            $matchedKey = filled($loadedItem['_id'] ?? null)
                ? ($keysById[(string) $loadedItem['_id']] ?? null)
                : null;
            $matchedKey ??= $loadedPath !== null ? ($keysByPath[$loadedPath] ?? null) : null;

            if ($matchedKey !== null && isset($usedCurrentKeys[$matchedKey])) {
                $matchedKey = null;
            }

            if ($matchedKey === null) {
                while (
                    isset($currentKeys[$fallbackIndex])
                    && isset($usedCurrentKeys[(string) $currentKeys[$fallbackIndex]])
                ) {
                    $fallbackIndex++;
                }

                $matchedKey = isset($currentKeys[$fallbackIndex])
                    ? (string) $currentKeys[$fallbackIndex++]
                    : (string) $loadedKey;
            }

            $usedCurrentKeys[$matchedKey] = true;
            $reconciledItems[$matchedKey] = $loadedItem;
        }

        return $this->withUploaderItem(
            $reconciledItems,
            $this->uploaderItemKey($currentItems),
        );
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function uploaderItemKey(array $items): ?string
    {
        foreach ($items as $key => $item) {
            if (is_array($item) && ($item['_is_uploader'] ?? false)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploaderItemState(): array
    {
        return [
            '_is_uploader' => true,
            'path' => [],
            'roles' => [],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, array<string, mixed>>
     */
    private function realItems(array $items): array
    {
        return array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false),
        );
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<int, mixed>  $order
     * @return array<array-key, array<string, mixed>>
     */
    private function reorderItems(array $items, array $order): array
    {
        $realItems = $this->realItems($items);
        $reorderedItems = [];

        foreach ($order as $itemKey) {
            if ((! is_string($itemKey) && ! is_int($itemKey)) || ! array_key_exists($itemKey, $realItems)) {
                continue;
            }

            $reorderedItems[$itemKey] = $realItems[$itemKey];
        }

        foreach ($realItems as $itemKey => $item) {
            if (! array_key_exists($itemKey, $reorderedItems)) {
                $reorderedItems[$itemKey] = $item;
            }
        }

        if (count($reorderedItems) >= $this->getMaximumImages()) {
            return $reorderedItems;
        }

        foreach ($items as $itemKey => $item) {
            if (is_array($item) && ($item['_is_uploader'] ?? false)) {
                $reorderedItems[$itemKey] = $item;

                return $reorderedItems;
            }
        }

        $reorderedItems[(string) Str::uuid()] = $this->uploaderItemState();

        return $reorderedItems;
    }

    public function getRealItemsCount(): int
    {
        return count($this->realItems($this->getRawState() ?? []));
    }

    public function getMaximumImages(): int
    {
        return (int) $this->getMaxItems();
    }

    public function isAddable(): bool
    {
        if ($this->isDisabled() || $this->getRealItemsCount() >= $this->getMaximumImages()) {
            return false;
        }

        return (bool) $this->evaluate($this->isAddable);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function getPreviewUrl(array $state): ?string
    {
        $file = collect(Arr::wrap($state['path'] ?? null))->first(
            fn (mixed $item): bool => is_string($item) || $item instanceof TemporaryUploadedFile,
        );

        if ($file instanceof TemporaryUploadedFile) {
            return $file->temporaryUrl();
        }

        if (! is_string($file) || blank($file) || ! is_numeric($state['_id'] ?? null)) {
            return null;
        }

        $image = $this->persistedImagesById()[(int) $state['_id']] ?? null;

        if (! $image instanceof GalleryImage || $image->path !== $file) {
            return null;
        }

        $storage = Storage::disk($image->disk);

        if ($image->visibility === 'private') {
            return $storage->temporaryUrl(
                $file,
                now()->addMinutes((int) config('filament-image-gallery.temporary_url_expiration', 60)),
            );
        }

        return $storage->url($file);
    }

    /**
     * @return array<int, GalleryImage>
     */
    private function persistedImagesById(): array
    {
        if ($this->persistedImages !== null) {
            return $this->persistedImages;
        }

        $record = $this->getRecord();

        if (! $record instanceof Model || ! $record->exists) {
            return $this->persistedImages = [];
        }

        $ids = collect($this->realItems($this->getRawState() ?? []))
            ->pluck('_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($ids === []) {
            return $this->persistedImages = [];
        }

        $imageClass = config('filament-image-gallery.models.image', GalleryImage::class);

        return $this->persistedImages = $imageClass::query()
            ->whereKey($ids)
            ->whereHas('gallery', fn ($query) => $query
                ->whereMorphedTo('owner', $record)
                ->where('collection', $this->getCollection()))
            ->get()
            ->keyBy(fn (GalleryImage $image): int => (int) $image->getKey())
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    public function normalizeRoles(array $items): array
    {
        $uploaderKey = $this->uploaderItemKey($items);
        $items = $this->realItems($items);
        $claimed = [];

        foreach (array_reverse(array_keys($items)) as $key) {
            $roles = array_values(array_intersect(Arr::wrap($items[$key]['roles'] ?? []), array_keys($this->getRoles())));
            $items[$key]['roles'] = array_values(array_filter($roles, function (string $role) use (&$claimed): bool {
                if (isset($claimed[$role])) {
                    return false;
                }

                $claimed[$role] = true;

                return true;
            }));
        }

        if ($items !== [] && ! isset($claimed['main'])) {
            $firstKey = array_key_first($items);
            $items[$firstKey]['roles'][] = 'main';
        }

        return $this->withUploaderItem($items, $uploaderKey);
    }

    public function collection(string|Closure|null $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function directory(string|Closure|null $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function visibility(string|Closure|null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function optimizeImages(bool|Closure|null $condition = true): static
    {
        $this->optimizeImages = $condition;

        return $this;
    }

    public function maxParallelUploads(int|Closure|null $count): static
    {
        $this->maxParallelUploads = $count;

        return $this;
    }

    /**
     * @param  array<string, string> | Closure  $roles
     */
    public function roles(array|Closure $roles): static
    {
        $this->roles = $roles;
        $this->resolvedRoles = null;

        return $this;
    }

    /**
     * @param  array<string> | Closure  $roles
     */
    public function defaultRoles(array|Closure $roles): static
    {
        $this->defaultRoles = $roles;

        return $this;
    }

    /**
     * @param  array<string> | Closure  $tools
     */
    public function tools(array|Closure $tools): static
    {
        $this->tools = $tools;
        $this->resolvedTools = null;

        return $this;
    }

    /**
     * @param  array<Action>  $actions
     */
    public function imageActions(array $actions): static
    {
        return $this->extraItemActions($actions);
    }

    public function getCollection(): string
    {
        return $this->evaluate($this->collection) ?? $this->getName();
    }

    public function getDisk(): string
    {
        return $this->evaluate($this->disk) ?? $this->getPlugin()?->getDisk() ?? config('filament-image-gallery.disk', 'public');
    }

    public function getDirectory(): string
    {
        return trim($this->evaluate($this->directory) ?? $this->getPlugin()?->getDirectory() ?? config('filament-image-gallery.directory', 'image-gallery'), '/');
    }

    public function getVisibility(): string
    {
        return $this->evaluate($this->visibility) ?? $this->getPlugin()?->getVisibility() ?? config('filament-image-gallery.visibility', 'public');
    }

    public function shouldOptimizeImages(): bool
    {
        return (bool) ($this->evaluate($this->optimizeImages) ?? $this->getPlugin()?->getOptimizeImages() ?? config('filament-image-gallery.optimize_images', true));
    }

    public function getMaximumParallelUploads(): int
    {
        return max(1, (int) ($this->evaluate($this->maxParallelUploads) ?? $this->getPlugin()?->getMaxParallelUploads() ?? config('filament-image-gallery.max_parallel_uploads', 4)));
    }

    /**
     * @return array<string, string>
     */
    public function getRoles(): array
    {
        $shouldCache = ! $this->roles instanceof Closure;

        if ($shouldCache && $this->resolvedRoles !== null) {
            return $this->resolvedRoles;
        }

        $roles = $this->evaluate($this->roles) ?? $this->getPlugin()?->getRoles() ?? config('filament-image-gallery.roles', []);

        if (! array_key_exists('main', $roles)) {
            throw new InvalidArgumentException('Image gallery roles must contain the required [main] role.');
        }

        $resolvedRoles = collect($roles)
            ->map(fn (string $label): string => str_contains($label, '::') ? __($label) : $label)
            ->all();

        if ($shouldCache) {
            $this->resolvedRoles = $resolvedRoles;
        }

        return $resolvedRoles;
    }

    /**
     * @return array<string>
     */
    public function getDefaultRoles(): array
    {
        $defaultRoles = array_values($this->evaluate($this->defaultRoles) ?? $this->getPlugin()?->getDefaultRoles() ?? config('filament-image-gallery.default_roles', ['main']));
        $unknownRoles = array_diff($defaultRoles, array_keys($this->getRoles()));

        if ($unknownRoles !== []) {
            throw new InvalidArgumentException('Default image roles must be present in the configured roles.');
        }

        return $defaultRoles;
    }

    /**
     * @return array<string>
     */
    public function getTools(): array
    {
        $shouldCache = ! $this->tools instanceof Closure;

        if ($shouldCache && $this->resolvedTools !== null) {
            return $this->resolvedTools;
        }

        $resolvedTools = array_values($this->evaluate($this->tools) ?? $this->getPlugin()?->getTools() ?? config('filament-image-gallery.tools', []));

        if ($shouldCache) {
            $this->resolvedTools = $resolvedTools;
        }

        return $resolvedTools;
    }

    private function getPlugin(): ?FilamentImageGalleryPlugin
    {
        try {
            if (! Filament::hasPlugin('filament-image-gallery')) {
                return null;
            }

            $plugin = Filament::getPlugin('filament-image-gallery');

            return $plugin instanceof FilamentImageGalleryPlugin ? $plugin : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     current: int,
     *     total: int,
     *     hasFocalPoint: bool,
     *     image: array<string, mixed>,
     *     thumbnails: array<int, array<string, mixed>>
     * }
     */
    private function editorPreviewData(string $itemKey): array
    {
        $items = $this->realItems($this->getRawState() ?? []);
        $keys = array_keys($items);
        $index = array_search($itemKey, $keys, true);
        $state = $items[$itemKey] ?? [];
        $hasFocalPoint = in_array('focal-point', $this->getTools(), true);

        return [
            'current' => $index === false ? 1 : $index + 1,
            'total' => count($items),
            'hasFocalPoint' => $hasFocalPoint,
            'image' => [
                'alt' => $state['alt_text'] ?? $state['title'] ?? '',
                'filename' => $state['original_filename'] ?? $state['title'] ?? __('filament-image-gallery::gallery.ui.untitled'),
                'format' => $this->formatLabel($state['mime_type'] ?? null),
                'height' => $state['height'] ?? null,
                'is_main' => in_array('main', Arr::wrap($state['roles'] ?? []), true),
                ...($hasFocalPoint ? [
                    'focal_x' => $state['focal_x'] ?? 50,
                    'focal_y' => $state['focal_y'] ?? 50,
                ] : []),
                'mime_type' => $state['mime_type'] ?? null,
                'size' => $this->formatFileSize($state['size'] ?? null),
                'url' => $state === [] ? null : $this->getPreviewUrl($state),
                'width' => $state['width'] ?? null,
            ],
            'thumbnails' => collect($items)
                ->map(fn (array $item, string|int $key): array => [
                    'alt' => $item['alt_text'] ?? $item['title'] ?? '',
                    'is_current' => (string) $key === $itemKey,
                    'url' => $this->getPreviewUrl($item),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editorFileDetails(Get $get): array
    {
        $savings = 0;

        if ((int) $get('original_size') > 0) {
            $savings = max(0, round((1 - ((int) $get('size') / (int) $get('original_size'))) * 100, 1));
        }

        return [
            'disk' => $get('disk') ?: $this->getDisk(),
            'filename' => $get('original_filename') ?: '—',
            'format' => $this->formatLabel($get('mime_type')),
            'height' => $get('height') ?: null,
            'mimeType' => $get('mime_type') ?: '—',
            'originalSize' => $this->formatFileSize($get('original_size')),
            'savings' => $savings,
            'size' => $this->formatFileSize($get('size')),
            'uploadedAt' => $get('uploaded_at') ?: '—',
            'uploader' => $get('uploader'),
            'visibility' => $get('visibility') ?: $this->getVisibility(),
            'width' => $get('width') ?: null,
        ];
    }

    private function formatFileSize(mixed $size): string
    {
        return filled($size) ? Number::fileSize((int) $size) : '—';
    }

    private function formatLabel(mixed $mimeType): string
    {
        if (! is_string($mimeType) || blank($mimeType)) {
            return '—';
        }

        $format = Str::of($mimeType)->after('/')->replace('jpeg', 'jpg')->upper()->value();

        return __('filament-image-gallery::gallery.editor.image_format', ['format' => $format]);
    }

    public function adjacentItemKey(string $itemKey, string $direction): ?string
    {
        $keys = array_keys($this->realItems($this->getRawState() ?? []));
        $index = array_search($itemKey, $keys, true);

        if ($index === false) {
            return null;
        }

        $nextIndex = $direction === 'previous' ? $index - 1 : $index + 1;

        return $keys[$nextIndex] ?? null;
    }

    public function editorHeading(string $itemKey): string
    {
        $keys = array_keys($this->realItems($this->getRawState() ?? []));
        $index = array_search($itemKey, $keys, true);

        if ($index === false) {
            return __('filament-image-gallery::gallery.editor.heading');
        }

        return __('filament-image-gallery::gallery.editor.heading_with_position', [
            'current' => $index + 1,
            'total' => count($keys),
        ]);
    }

    private function fileIdentity(mixed $state): ?string
    {
        foreach (Arr::wrap($state) as $file) {
            if (is_string($file)) {
                return $file;
            }

            if ($file instanceof TemporaryUploadedFile) {
                return 'temporary:'.$file->getFilename();
            }
        }

        return null;
    }
}
