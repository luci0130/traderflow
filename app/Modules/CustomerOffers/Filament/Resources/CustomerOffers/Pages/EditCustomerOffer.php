<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages;

use App\Modules\CustomerOffers\Enums\CustomerOfferPdfTemplate;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\CustomerOffers\Services\CustomerOfferDuplicator;
use App\Modules\CustomerOffers\Services\CustomerOfferEmailSender;
use App\Modules\CustomerOffers\Services\CustomerOfferExcelExporter;
use App\Modules\CustomerOffers\Services\CustomerOfferPdfExporter;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\SalesOrderResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditCustomerOffer extends EditRecord
{
    protected static string $resource = CustomerOfferResource::class;

    #[On('offer-items-changed')]
    public function refreshOfferTotals(): void
    {
        $this->refreshFormData(['subtotal', 'tax_total', 'total']);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->hidden(fn (): bool => $this->getRecord()->fresh()->isLocked());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $offer = CustomerOffer::query()->lockForUpdate()->findOrFail($record->getKey());
            Gate::authorize('update', $offer);
            abort_if($offer->isLocked(), 403);

            return parent::handleRecordUpdate($offer, $data);
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('duplicateOffer')
                ->label(__('Duplicate offer'))
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->visible(fn (CustomerOffer $record): bool => CustomerOfferResource::canCreate() && CustomerOfferResource::canView($record))
                ->requiresConfirmation()
                ->modalDescription(__('Create a draft copy with a new number, the same products and suppliers, and no linked orders.'))
                ->action(function (CustomerOffer $record): void {
                    $duplicate = app(CustomerOfferDuplicator::class)->duplicate($record);
                    $this->redirect(CustomerOfferResource::getUrl('edit', ['record' => $duplicate]));
                }),
            Action::make('acceptOffer')
                ->label(__('Accept offer'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Accept offer'))
                ->modalDescription(__('Accepting marks the offer as accepted, creates the customer order (sale price × secured quantity) and one supplier order per chosen supplier with the quantity it secured.'))
                ->visible(fn (CustomerOffer $record): bool => ! $record->isLocked())
                ->action(function (CustomerOffer $record): void {
                    try {
                        $salesOrder = app(CustomerOfferAcceptor::class)->accept($record);
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->title(__('Acceptance failed'))
                            ->body(__($exception->getMessage()))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Offer accepted'))
                        ->body(__('Sales order :id and the supplier orders were created.', ['id' => $salesOrder->getKey()]))
                        ->success()
                        ->send();

                    $this->redirect(SalesOrderResource::getUrl('edit', [
                        'record' => $salesOrder,
                    ]));
                }),
            Action::make('generateOfferExcel')
                ->label(__('Generate Excel offer'))
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(function (CustomerOffer $record): StreamedResponse {
                    $path = app(CustomerOfferExcelExporter::class)->export($record);

                    $filename = 'oferta-'.($record->offer_number ?: $record->getKey()).'.xlsx';

                    return response()->streamDownload(function () use ($path): void {
                        readfile($path);
                        @unlink($path);
                    }, $filename, [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
            Action::make('generateOfferPdf')
                ->label(__('Generate PDF offer'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('danger')
                ->modalHeading(__('Choose offer template'))
                ->modalSubmitActionLabel(__('Generate PDF'))
                ->schema([
                    Radio::make('template')
                        ->label(__('Offer template'))
                        ->options(CustomerOfferPdfTemplate::options())
                        ->default(CustomerOfferPdfTemplate::Complex->value)
                        ->required()
                        ->rules([Rule::enum(CustomerOfferPdfTemplate::class)]),
                ])
                ->action(function (CustomerOffer $record, array $data): StreamedResponse {
                    Gate::authorize('view', $record);
                    $path = app(CustomerOfferPdfExporter::class)->export($record, CustomerOfferPdfTemplate::from($data['template']));

                    $filename = 'oferta-'.($record->offer_number ?: $record->getKey()).'.pdf';

                    return response()->streamDownload(function () use ($path): void {
                        readfile($path);
                        @unlink($path);
                    }, $filename, [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),
            Action::make('sendOfferEmail')
                ->visible(fn (CustomerOffer $record): bool => ! $record->isLocked())
                ->label(__('Send Offer Email'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->modalSubmitActionLabel(__('Send'))
                ->fillForm(function (CustomerOffer $record): array {
                    $record->loadMissing('customer');
                    $defaults = app(CustomerOfferEmailSender::class)->defaultsFor($record);

                    return [
                        'to' => $record->customer?->email ?? '',
                        'cc' => '',
                        'subject' => $defaults['subject'],
                        'body' => $defaults['body'],
                    ];
                })
                ->form([
                    TextInput::make('to')
                        ->label(__('To'))
                        ->required()
                        ->helperText(__('Separate multiple addresses with commas.'))
                        ->rules(['required', 'string', $this->emailListRule()]),
                    TextInput::make('cc')
                        ->label(__('Cc'))
                        ->helperText(__('Separate multiple addresses with commas.'))
                        ->rules(['nullable', 'string', $this->emailListRule()]),
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->required()
                        ->maxLength(255),
                    RichEditor::make('body')
                        ->label(__('Message'))
                        ->required(),
                ])
                ->action(function (CustomerOffer $record, array $data): void {
                    abort_if($record->fresh()->isLocked(), 403);
                    $email = app(CustomerOfferEmailSender::class)->send($record, $data);

                    if ($email->status === 'sent') {
                        Notification::make()
                            ->title(__('Email sent'))
                            ->body(__('The offer was emailed and marked as sent.'))
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Email failed'))
                        ->body($email->error_message ?? __('The email could not be delivered.'))
                        ->danger()
                        ->send();
                }),
            DeleteAction::make()
                ->visible(fn (CustomerOffer $record): bool => ! $record->isLocked())
                ->before(fn (CustomerOffer $record) => abort_if($record->fresh()->isLocked(), 403)),
        ];
    }

    /**
     * A comma/semicolon-separated email-list validation rule.
     *
     * Wrapped in an outer closure: Filament evaluates closures passed to
     * `rules()` (injecting its own utilities) to resolve the actual rule, so the
     * raw Laravel closure rule (which expects `$attribute`, `$value`, `$fail`)
     * must be returned from it rather than passed directly.
     */
    private function emailListRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            foreach (preg_split('/[,;]/', (string) $value) ?: [] as $piece) {
                $address = trim($piece);

                if ($address === '') {
                    continue;
                }

                if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $fail("[{$address}] is not a valid email address.");
                }
            }
        };
    }
}
