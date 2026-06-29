<?php

namespace App\Modules\Producers\Filament\Resources\SupplierProducts\Pages;

use App\Modules\Producers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Producers\Models\SupplierProduct;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;

class ListSupplierProducts extends ListRecords
{
    protected static string $resource = SupplierProductResource::class;

    /**
     * @var array<string, mixed>
     */
    public array $quickProducts = [];

    public function mount(): void
    {
        parent::mount();

        $this->resetQuickProducts();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make(__('Quick add products'))
                        ->compact()
                        ->schema([
                            EmbeddedSchema::make('quickCreateForm'),
                        ]),
                ])
                    ->id('quick-products')
                    ->livewireSubmitHandler('createQuickProducts')
                    ->footer([
                        Actions::make([
                            Action::make('createQuickProducts')
                                ->label(__('Save products'))
                                ->icon(Heroicon::OutlinedPlusCircle)
                                ->submit('quick-products'),
                        ]),
                    ]),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function quickCreateForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('quickProducts')
            ->components([
                Repeater::make('rows')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Photo'))->width('120px'),
                        TableColumn::make(__('Name'))->width('220px'),
                        TableColumn::make(__('Packaging'))->width('170px'),
                        TableColumn::make(__('Quantity available'))->width('170px'),
                        TableColumn::make(__('Offer valid until'))->width('170px'),
                        TableColumn::make(__('Unit'))->width('110px'),
                        TableColumn::make(__('Currency'))->width('120px'),
                        TableColumn::make(__('Minimum quantity'))->width('170px'),
                        TableColumn::make(__('Price per unit'))->width('170px'),
                    ])
                    ->schema(SupplierProductResource::quickProductRowFields())
                    ->defaultItems(1)
                    ->addActionLabel(__('Add another product'))
                    ->reorderable(false),
            ]);
    }

    public function createQuickProducts(): void
    {
        $data = $this->quickCreateForm->getState();
        $producerId = auth()->user()?->producer_id;

        if ($producerId === null) {
            Notification::make()
                ->title(__('Producer account is missing.'))
                ->danger()
                ->send();

            return;
        }

        $createdCount = 0;

        DB::transaction(function () use ($data, $producerId, &$createdCount): void {
            foreach ($data['rows'] ?? [] as $row) {
                $product = SupplierProduct::create([
                    'producer_id' => $producerId,
                    'image_path' => $row['image_path'] ?? null,
                    'name' => $row['name'],
                    'packaging_method_id' => $row['packaging_method_id'] ?? null,
                    'quantity_available' => $row['quantity_available'] ?? null,
                    'valid_until' => $row['valid_until'],
                    'min_quantity_value' => $row['min_quantity_value'],
                    'min_quantity_unit' => $row['min_quantity_unit'],
                    'unit_price' => $row['unit_price'],
                    'currency' => $row['currency'],
                    'status' => 'active',
                ]);

                SupplierProductResource::replacePriceBreaks($product, [[
                    'min_quantity_value' => $row['min_quantity_value'],
                    'unit_price' => $row['unit_price'],
                ]]);

                $createdCount++;
            }
        });

        $this->resetQuickProducts();
        $this->resetTable();

        Notification::make()
            ->title(trans_choice(':count product saved|:count products saved', $createdCount, ['count' => $createdCount]))
            ->success()
            ->send();
    }

    protected function resetQuickProducts(): void
    {
        $this->quickProducts = [
            'rows' => [
                [
                    'min_quantity_unit' => 'kg',
                    'currency' => 'EUR',
                ],
            ],
        ];

        $this->quickCreateForm->fill($this->quickProducts);
    }
}
