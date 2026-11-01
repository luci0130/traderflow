<?php

namespace App\Modules\Suppliers\Filament\Actions;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Filament\Pages\SupplierProductsV2;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierProductCsvImport;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ImportSupplierProductsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'importSupplierProducts';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $resetValidation = function (Set $set): void {
            $set('validation_token', null);
            $set('validation_report', null);
        };

        $this->label(__('Import CSV'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->authorize(fn (): bool => Gate::allows('create', SupplierProduct::class))
            ->modalHeading(__('Import supplier products'))
            ->modalDescription(__('Choose a supplier, upload the completed CSV template and validate it. Products are saved only when you press Import products. Maximum 1,000 products and 2 MB per file.'))
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                Select::make('supplier_id')
                    ->label(__('Supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated($resetValidation),
                FileUpload::make('file')
                    ->label(__('CSV file'))
                    ->helperText(__('Use UTF-8, comma or semicolon separators, dates YYYY-MM-DD and 0/1 for is_bio. Each row creates a new product.').' '.__('Minimum quantity is optional. Leave empty if unknown.'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'])
                    ->rules(['extensions:csv'])
                    ->maxSize(SupplierProductCsvImport::MAX_SIZE_KB)
                    ->storeFiles(false)
                    ->preventFilePathTampering()
                    ->required()
                    ->live()
                    ->afterStateUpdated($resetValidation),
                Hidden::make('validation_token'),
                Textarea::make('validation_report')
                    ->label(__('CSV validation result'))
                    ->readOnly()
                    ->rows(6)
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => filled($get('validation_report'))),
            ])
            ->modalSubmitActionLabel(__('Import products'))
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(fn (): bool => blank($this->getRawData()['validation_token'] ?? null)))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('validateCsv', arguments: ['validateOnly' => true])
                    ->label(__('Validate CSV'))
                    ->color('gray')
                    ->icon(Heroicon::OutlinedCheckCircle),
                Action::make('downloadCsvTemplate')
                    ->label(__('Download template'))
                    ->color('gray')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (SupplierProductCsvImport $importer) => $importer->downloadTemplate()),
            ])
            ->action(function (array $data, array $arguments, Schema $schema, Component $livewire, SupplierProductCsvImport $importer): void {
                Gate::authorize('create', SupplierProduct::class);
                $supplierId = (int) $data['supplier_id'];
                Supplier::query()->findOrFail($supplierId);

                try {
                    if ($arguments['validateOnly'] ?? false) {
                        $rows = $importer->validateFile($data['file']);
                        $schema->fillPartially([
                            'validation_token' => $importer->validationToken($data['file'], $supplierId),
                            'validation_report' => __('CSV valid: :count products ready to import. No products have been saved yet.', ['count' => count($rows)]),
                        ], ['validation_token', 'validation_report']);
                        $this->resetArguments();
                        $this->halt();
                    }

                    $count = $importer->import($data['file'], $supplierId, $data['validation_token'] ?? '');
                } catch (ValidationException $exception) {
                    $schema->fillPartially([
                        'validation_token' => null,
                        'validation_report' => implode("\n", Arr::flatten($exception->errors())),
                    ], ['validation_token', 'validation_report']);
                    $this->resetArguments();
                    $this->halt();
                }

                $data['file']->delete();
                Notification::make()->success()
                    ->title(__(':count supplier products imported.', ['count' => $count]))
                    ->send();

                $livewire->redirect($livewire instanceof SupplierProductsV2
                    ? SupplierProductsV2::getUrl()
                    : SupplierProductResource::getUrl('index'));
            });
    }
}
