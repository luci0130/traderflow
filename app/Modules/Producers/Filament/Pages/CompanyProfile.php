<?php

namespace App\Modules\Producers\Filament\Pages;

use App\Modules\Producers\Models\Producer;
use App\Support\Countries;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class CompanyProfile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 10;

    protected string $view = 'producers.filament.pages.company-profile';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Account');
    }

    public static function getNavigationLabel(): string
    {
        return __('Company profile');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Company profile');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Company profile');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('These details are used to generate invoices and identify your company on every offer.');
    }

    public function mount(): void
    {
        $this->form->fill($this->getRecord()?->attributesToArray() ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Identity'))
                    ->description(__('Public-facing name and legal identifiers.'))
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label(__('Company logo'))
                            ->image()
                            ->disk('public')
                            ->directory('producer-logos')
                            ->maxSize(2048)
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label(__('Trading name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Acme Foods'),
                        TextInput::make('legal_name')
                            ->label(__('Legal name'))
                            ->maxLength(255)
                            ->placeholder('Acme Foods SRL'),
                        TextInput::make('vat_number')
                            ->label(__('VAT number'))
                            ->maxLength(32)
                            ->placeholder('RO12345678'),
                        TextInput::make('registration_number')
                            ->label(__('Registration number'))
                            ->helperText(__('Trade Register number (e.g., J40/1234/2024 for Romania).'))
                            ->maxLength(64)
                            ->placeholder('J40/1234/2024'),
                    ])
                    ->columns(2),

                Section::make(__('Contact & address'))
                    ->description(__('Where invoices and shipping documents should be addressed.'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->maxLength(255)
                            ->placeholder('hello@acmefoods.eu'),
                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->maxLength(32)
                            ->placeholder('+40 21 555 0100'),
                        Select::make('country')
                            ->label(__('Country'))
                            ->options(Countries::options())
                            ->searchable()
                            ->default('RO'),
                        TextInput::make('city')
                            ->label(__('City'))
                            ->maxLength(255)
                            ->placeholder('București'),
                        TextInput::make('postal_code')
                            ->label(__('Postal code'))
                            ->maxLength(16)
                            ->placeholder('010101'),
                        Textarea::make('address')
                            ->label(__('Street address'))
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Str. Exemplu 12, Sector 1'),
                    ])
                    ->columns(2),

                Section::make(__('Banking'))
                    ->description(__('Account information shown on invoices.'))
                    ->schema([
                        TextInput::make('iban')
                            ->label(__('IBAN'))
                            ->maxLength(34)
                            ->placeholder('RO49AAAA1B31007593840000'),
                        TextInput::make('bank_name')
                            ->label(__('Bank name'))
                            ->maxLength(255)
                            ->placeholder('Banca Transilvania'),
                        TextInput::make('bank_swift')
                            ->label(__('SWIFT/BIC'))
                            ->maxLength(11)
                            ->placeholder('BTRLRO22'),
                        Select::make('default_currency')
                            ->label(__('Default currency'))
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(__('Invoicing'))
                    ->description(__('How invoices issued from your sales should look.'))
                    ->schema([
                        TextInput::make('invoice_prefix')
                            ->label(__('Invoice prefix'))
                            ->maxLength(16)
                            ->placeholder('ACME-'),
                        TextInput::make('invoice_starting_number')
                            ->label(__('Next invoice number'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        Textarea::make('invoice_notes')
                            ->label(__('Footer notes'))
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder(__('Thank you for your order. Payment due within 14 days.')),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $producer = $this->getRecord();

        abort_if($producer === null, 404, __('No producer found for this account.'));

        $producer->fill($this->form->getState());
        $producer->save();

        Notification::make()
            ->success()
            ->title(__('Company profile updated'))
            ->send();
    }

    public function getRecord(): ?Producer
    {
        return auth()->user()?->producer;
    }
}
