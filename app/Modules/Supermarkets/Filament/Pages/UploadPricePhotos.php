<?php

namespace App\Modules\Supermarkets\Filament\Pages;

use App\Modules\Customers\Enums\CustomerLocationType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerLocation;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UploadPricePhotos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Supermarkets';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'supermarket-price-photos/upload';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupermarketPrice') ?? false;
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'taken_at' => today(),
        ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('Upload price photos');
    }

    public function getTitle(): string
    {
        return __('Upload price photos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Supermarkets');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make(__('Upload price photos'))
                        ->description(__('Pick the supermarket, then upload every shelf photo you took. Each photo becomes a record to review.'))
                        ->schema([
                            EmbeddedSchema::make('form'),
                        ]),
                ])
                    ->id('upload-photos')
                    ->livewireSubmitHandler('storePhotos')
                    ->footer([
                        Actions::make([
                            Action::make('storePhotos')
                                ->label(__('Upload'))
                                ->icon(Heroicon::OutlinedArrowUpTray)
                                ->submit('upload-photos'),
                            Action::make('review')
                                ->label(__('Go to review'))
                                ->color('gray')
                                ->icon(Heroicon::OutlinedArrowRight)
                                ->url(fn (): string => ReviewPricePhotos::getUrl()),
                        ]),
                    ]),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Select::make('supermarket_id')
                    ->label('Supermarket')
                    ->options(fn (): array => Customer::query()->withoutGlobalScope('active_tenant')->global()->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->afterStateUpdated(function (callable $set): void {
                        $set('customer_location_id', null);
                    })
                    ->searchable()
                    ->required(),
                Select::make('customer_location_id')
                    ->label('Store / location')
                    ->options(fn (Get $get): array => CustomerLocation::query()
                        ->where('customer_id', $get('supermarket_id'))
                        ->orderBy('city')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (CustomerLocation $location): array => [$location->getKey() => $location->displayName()])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (Get $get): bool => blank($get('supermarket_id')))
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->label(__('Type'))
                            ->options(CustomerLocationType::options())
                            ->default(CustomerLocationType::Supermarket->value)
                            ->required(),
                        TextInput::make('county')
                            ->label(__('Judet'))
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label(__('City'))
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label(__('Address'))
                            ->columnSpanFull(),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $supermarketId = $this->data['supermarket_id'] ?? null;

                        if (blank($supermarketId)) {
                            throw ValidationException::withMessages([
                                'data.supermarket_id' => __('Select a supermarket first.'),
                            ]);
                        }

                        $supermarket = Customer::query()
                            ->withoutGlobalScope('active_tenant')
                            ->global()
                            ->whereKey($supermarketId)
                            ->firstOrFail();

                        return CustomerLocation::create([
                            'tenant_id' => $supermarket->tenant_id,
                            'customer_id' => $supermarket->getKey(),
                            'name' => $data['name'],
                            'type' => $data['type'],
                            'county' => $data['county'] ?? null,
                            'city' => $data['city'] ?? null,
                            'address' => $data['address'] ?? null,
                        ])->getKey();
                    }),
                DatePicker::make('taken_at')
                    ->label('Photos taken on')
                    ->default(today()),
                FileUpload::make('photos')
                    ->label('Photos')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->appendFiles()
                    ->disk('public')
                    ->directory('supermarket-photos')
                    ->maxSize(10240)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function storePhotos(): void
    {
        $data = $this->form->getState();
        $location = CustomerLocation::query()
            ->whereKey($data['customer_location_id'] ?? null)
            ->where('customer_id', $data['supermarket_id'])
            ->first();

        if ($location === null) {
            throw ValidationException::withMessages([
                'data.customer_location_id' => __('Select a valid store / location.'),
            ]);
        }

        $paths = collect($data['photos'] ?? [])->filter()->values();

        foreach ($paths as $path) {
            SupermarketPricePhoto::create([
                'supermarket_id' => $data['supermarket_id'],
                'customer_location_id' => $location->getKey(),
                'uploaded_by' => auth()->id(),
                'path' => $path,
                'store_label' => $location->displayName(),
                'taken_at' => $data['taken_at'] ?? today(),
                'status' => SupermarketPricePhoto::STATUS_PENDING,
            ]);
        }

        Notification::make()
            ->title(trans_choice(':count photo uploaded|:count photos uploaded', $paths->count(), ['count' => $paths->count()]))
            ->success()
            ->send();

        // The photo records now own the files; clear only the upload field.
        $this->form->fill([
            'supermarket_id' => $data['supermarket_id'],
            'customer_location_id' => $location->getKey(),
            'taken_at' => $data['taken_at'] ?? today(),
            'photos' => [],
        ]);
    }
}
