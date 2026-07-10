<?php

namespace App\Modules\Supermarkets\Filament\Pages;

use App\Modules\Customers\Models\Customer;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\SupplierLead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class ReviewPricePhotos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Supermarkets';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'supermarket-price-photos/review';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupermarketPrice') ?? false;
    }

    public ?int $photoId = null;

    /**
     * @var array<int>
     */
    public array $skippedIds = [];

    /**
     * Unsaved supplier-lead modal input, kept on the component so closing the
     * modal (or switching between the header/inline buttons) does not discard
     * what the reviewer already typed. Cleared once a lead is saved.
     *
     * @var array<string, mixed>
     */
    public array $supplierLeadDraft = [];

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * The photo the currently cached content/form schemas were built for. Used
     * to detect when the reviewer has advanced to a different photo within the
     * same request (see {@see rendering()}).
     */
    protected ?int $renderedPhotoId = null;

    public function mount(): void
    {
        $this->loadNextPhoto();
    }

    public static function getNavigationLabel(): string
    {
        return __('Review price photos');
    }

    public function getTitle(): string
    {
        return __('Review price photos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Supermarkets');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SupermarketPricePhoto::query()
            ->whereIn('status', [SupermarketPricePhoto::STATUS_PENDING, SupermarketPricePhoto::STATUS_IN_REVIEW])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->addSupplierLeadAction('addSupplierLead'),
        ];
    }

    /**
     * Build the "Add supplier lead" modal action. Reused in the page header and
     * under the repeater's "Add product" button so reviewers can capture a
     * potential supplier from either spot.
     */
    private function addSupplierLeadAction(string $name): Action
    {
        $rememberDraft = function (Get $get): void {
            $this->supplierLeadDraft = [
                'name' => $get('name'),
                'country' => $get('country'),
                'website' => $get('website'),
                'email' => $get('email'),
                'phone' => $get('phone'),
                'notes' => $get('notes'),
                'supermarket_product_id' => $get('supermarket_product_id'),
            ];
        };

        return Action::make($name)
            ->label(__('Add supplier lead'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('Create:SupplierLead') ?? false)
            ->modalHeading(__('Add supplier lead'))
            ->modalDescription(__('Save the contact details of a potential supplier. This is only a lead; whoever contacts them turns it into a supplier.'))
            ->closeModalByClickingAway(false)
            // Default the linked product to the one being reviewed in the photo,
            // unless the reviewer already picked (or cleared) one in the draft.
            ->fillForm(fn (): array => [
                ...$this->supplierLeadDraft,
                'supermarket_product_id' => $this->supplierLeadDraft['supermarket_product_id'] ?? $this->reviewedProductId(),
            ])
            ->schema([
                Select::make('supermarket_product_id')
                    ->label(__('Product'))
                    ->helperText(__('The supermarket product this potential supplier could provide.'))
                    ->placeholder(__('Select a product'))
                    ->options(fn (): array => SupermarketProduct::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated($rememberDraft),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
                TextInput::make('country')
                    ->label(__('Country'))
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
                TextInput::make('website')
                    ->label(__('Website'))
                    ->url()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
                Textarea::make('notes')
                    ->label(__('Notes about supplier'))
                    ->live(onBlur: true)
                    ->afterStateUpdated($rememberDraft),
            ])
            ->action(function (array $data): void {
                SupplierLead::create([
                    'name' => $data['name'],
                    'country' => $data['country'] ?? null,
                    'website' => $data['website'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'supermarket_product_id' => $data['supermarket_product_id'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                // The lead is persisted; drop the retained draft so the next
                // time the modal opens it starts blank.
                $this->supplierLeadDraft = [];

                Notification::make()
                    ->title(__('Supplier lead saved'))
                    ->success()
                    ->send();
            });
    }

    public function currentPhoto(): ?SupermarketPricePhoto
    {
        return $this->photoId !== null
            ? SupermarketPricePhoto::with('supermarket')->find($this->photoId)
            : null;
    }

    protected function photoUrl(SupermarketPricePhoto $photo): string
    {
        return Storage::disk('public')->url($photo->path);
    }

    protected function loadNextPhoto(): void
    {
        $photo = SupermarketPricePhoto::query()
            ->whereIn('status', [SupermarketPricePhoto::STATUS_PENDING, SupermarketPricePhoto::STATUS_IN_REVIEW])
            ->whereNotIn('id', $this->skippedIds)
            ->orderBy('id')
            ->first();

        if ($photo === null) {
            $this->photoId = null;
            $this->form->fill();

            return;
        }

        $photo->update(['status' => SupermarketPricePhoto::STATUS_IN_REVIEW]);
        $this->photoId = $photo->getKey();

        $this->form->fill([
            'supermarket_id' => $photo->supermarket_id,
            'observed_at' => $photo->taken_at?->toDateString() ?? today()->toDateString(),
            'currency' => 'RON',
            'entries' => [$this->defaultEntryState()],
        ]);
    }

    /**
     * The content schema (photo preview) and its embedded form schema are cached
     * per request, bound to the photo they were built for. Advancing to another
     * photo within the same request (save/skip/delete) updates the component
     * state but, for the form-submit "save" flow, Filament re-caches those
     * schemas against the previous photo while handling the submit — so the
     * action's closing render would keep showing it. Dropping the caches right
     * before rendering, once the photo has changed, forces a rebuild from the
     * fresh state.
     */
    public function rendering(): void
    {
        if ($this->hasCachedSchema('content') && $this->renderedPhotoId !== $this->photoId) {
            $this->cacheSchema('form', null);
            $this->cacheSchema('content', null);
        }
    }

    public function content(Schema $schema): Schema
    {
        $this->renderedPhotoId = $this->photoId;

        $photo = $this->currentPhoto();

        if ($photo === null) {
            return $schema->components([
                View::make('filament.supermarkets.partials.review-empty'),
            ]);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema([
                    View::make('filament.supermarkets.partials.review-photo')
                        ->viewData([
                            'photoUrl' => $this->photoUrl($photo),
                            'supermarketName' => $photo->supermarket?->name,
                            'storeLabel' => $photo->store_label,
                        ]),
                    Form::make([
                        EmbeddedSchema::make('form'),
                    ])
                        ->id('review')
                        ->livewireSubmitHandler('save')
                        ->footer([
                            Actions::make([
                                Action::make('save')
                                    ->label(__('Save & next'))
                                    ->icon(Heroicon::OutlinedCheck)
                                    ->submit('review'),
                                Action::make('skip')
                                    ->label(__('Skip'))
                                    ->color('gray')
                                    ->icon(Heroicon::OutlinedForward)
                                    ->action('skip'),
                                Action::make('deletePhoto')
                                    ->label(__('Delete photo'))
                                    ->color('danger')
                                    ->icon(Heroicon::OutlinedTrash)
                                    ->requiresConfirmation()
                                    ->action('deletePhoto'),
                            ]),
                        ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('supermarket_id')
                            ->label('Supermarket')
                            ->hiddenLabel()
                            ->placeholder(__('Supermarket'))
                            ->options(fn (): array => Customer::query()->withoutGlobalScope('active_tenant')->global()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        DatePicker::make('observed_at')
                            ->label('Observed on')
                            ->hiddenLabel()
                            ->required(),
                        Select::make('currency')
                            ->hiddenLabel()
                            ->placeholder(__('Currency'))
                            ->options([
                                'RON' => 'RON',
                                'EUR' => 'EUR',
                                'USD' => 'USD',
                            ])
                            ->default('RON')
                            ->required(),
                    ])
                    ->columnSpanFull(),
                Repeater::make('entries')
                    ->label('Products in this photo')
                    ->hiddenLabel()
                    ->compact()
                    ->default([$this->defaultEntryState()])
                    ->schema([
                        Select::make('supermarket_product_id')
                            ->label('Find existing product')
                            ->hiddenLabel()
                            ->placeholder(__('Find existing product or create a new one below'))
                            ->options(fn (): array => SupermarketProduct::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live()
                            ->columnSpanFull(),
                        Section::make()
                            ->schema(SupermarketProductResource::formComponents(compact: true, includeImage: false))
                            ->columns(['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4])
                            ->compact()
                            ->secondary()
                            ->visible(fn (Get $get): bool => blank($get('supermarket_product_id'))),
                        Grid::make(3)
                            ->schema([
                                TextInput::make('price')
                                    ->hiddenLabel()
                                    ->placeholder(__('Price'))
                                    ->numeric()
                                    ->step('0.0001')
                                    ->minValue(0)
                                    ->required(),
                                Toggle::make('is_promo')
                                    ->label('Promo')
                                    ->live()
                                    ->inline()
                                    ->extraFieldWrapperAttributes([
                                        'style' => 'height: 100%; display: flex; align-items: center;',
                                    ]),
                                TextInput::make('promo_price')
                                    ->hiddenLabel()
                                    ->placeholder(__('Promo price'))
                                    ->numeric()
                                    ->step('0.0001')
                                    ->minValue(0)
                                    ->visible(fn (Get $get): bool => (bool) $get('is_promo')),
                            ]),
                    ])
                    ->columns(1)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->addActionLabel(__('Add product'))
                    ->reorderable(false),
                Actions::make([
                    $this->addSupplierLeadAction('addSupplierLeadInline'),
                ])
                    ->key('supplier-lead-actions'),
            ]);
    }

    public function save(): void
    {
        $photo = $this->currentPhoto();

        if ($photo === null) {
            return;
        }

        $data = $this->form->getState();

        DB::transaction(function () use ($photo, $data): void {
            foreach ($data['entries'] as $entry) {
                $productId = $entry['supermarket_product_id'] ?? null;

                if (blank($productId)) {
                    $productId = SupermarketProduct::create([
                        'name' => $entry['name'] ?? null,
                        'brand' => $entry['brand'] ?? null,
                        'category' => $entry['category'] ?? null,
                        'origin' => $entry['origin'] ?? null,
                        'caliber' => $entry['caliber'] ?? null,
                        'quality' => $entry['quality'] ?? null,
                        'barcode' => $entry['barcode'] ?? null,
                        'packaging_method_id' => $entry['packaging_method_id'] ?? null,
                        'package_size' => $entry['package_size'] ?? null,
                        'package_unit' => $entry['package_unit'] ?? null,
                        'vat_rate' => $entry['vat_rate'] ?? SupermarketProduct::DEFAULT_VAT_RATE,
                        'image_path' => $entry['image_path'] ?? null,
                    ])->getKey();
                }

                SupermarketPrice::create([
                    'supermarket_id' => $data['supermarket_id'],
                    'supermarket_product_id' => $productId,
                    'supermarket_price_photo_id' => $photo->getKey(),
                    'price' => $entry['price'],
                    'currency' => $data['currency'],
                    'is_promo' => $entry['is_promo'] ?? false,
                    'promo_price' => ($entry['is_promo'] ?? false) ? ($entry['promo_price'] ?? null) : null,
                    'observed_at' => $data['observed_at'],
                    'source' => SupermarketPrice::SOURCE_PHOTO,
                    'recorded_by' => auth()->id(),
                ]);
            }

            $photo->update(['status' => SupermarketPricePhoto::STATUS_DONE]);
        });

        Notification::make()
            ->title(__(':count price(s) saved', ['count' => count($data['entries'])]))
            ->success()
            ->send();

        $this->loadNextPhoto();
    }

    public function skip(): void
    {
        if ($this->photoId !== null) {
            SupermarketPricePhoto::query()
                ->whereKey($this->photoId)
                ->update(['status' => SupermarketPricePhoto::STATUS_PENDING]);

            $this->skippedIds[] = $this->photoId;
        }

        $this->loadNextPhoto();
    }

    public function deletePhoto(): void
    {
        $photo = $this->currentPhoto();

        if ($photo !== null) {
            $photo->delete();
        }

        $this->loadNextPhoto();
    }

    /**
     * The existing supermarket product currently selected in the review form,
     * used to pre-fill the linked product when capturing a supplier lead. Falls
     * back to the first entry that references a saved product.
     */
    protected function reviewedProductId(): ?int
    {
        foreach ($this->data['entries'] ?? [] as $entry) {
            if (! blank($entry['supermarket_product_id'] ?? null)) {
                return (int) $entry['supermarket_product_id'];
            }
        }

        return null;
    }

    /**
     * @return array{is_promo: bool, vat_rate: float}
     */
    private function defaultEntryState(): array
    {
        return [
            'is_promo' => false,
            'vat_rate' => SupermarketProduct::DEFAULT_VAT_RATE,
        ];
    }
}
