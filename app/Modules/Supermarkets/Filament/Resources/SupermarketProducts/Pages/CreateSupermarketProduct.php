<?php

namespace App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages;

use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use App\Modules\Suppliers\Models\SupplierLead;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Icons\Heroicon;

class CreateSupermarketProduct extends CreateRecord
{
    protected static string $resource = SupermarketProductResource::class;

    /**
     * Leads captured through the "Add supplier lead" modal before the product
     * exists. They are persisted, linked to the product, once it is created.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $pendingSupplierLeads = [];

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->addSupplierLeadAction(),
        ];
    }

    /**
     * Capture a potential supplier for the product being created. The product
     * does not have an ID yet, so the lead is queued and saved by
     * {@see afterCreate()}.
     */
    private function addSupplierLeadAction(): Action
    {
        return Action::make('addSupplierLead')
            ->label(__('Add supplier lead'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('Create:SupplierLead') ?? false)
            ->modalHeading(__('Add supplier lead'))
            ->modalDescription(__('Save the contact details of a potential supplier. This is only a lead; whoever contacts them turns it into a supplier.'))
            ->modalSubmitActionLabel(__('Add supplier lead'))
            ->closeModalByClickingAway(false)
            ->schema([
                TextEntry::make('supermarket_product')
                    ->label(__('Product'))
                    ->state(fn (): string => filled($this->data['name'] ?? null)
                        ? (string) $this->data['name']
                        : __('The product you are creating'))
                    ->helperText(__('The lead is linked to this product once you save it.')),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('address')->label(__('Address')),
                TextInput::make('country')
                    ->label(__('Country'))
                    ->maxLength(255),
                TextInput::make('website')
                    ->label(__('Website'))
                    ->url()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label(__('Notes about supplier')),
            ])
            ->action(function (array $data): void {
                $this->pendingSupplierLeads[] = [
                    'name' => $data['name'],
                    'country' => $data['country'] ?? null,
                    'address' => $data['address'] ?? null,
                    'website' => $data['website'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ];

                Notification::make()
                    ->title(__('Supplier lead added'))
                    ->body(__('It is saved together with the product.'))
                    ->success()
                    ->send();
            });
    }

    protected function afterCreate(): void
    {
        foreach ($this->pendingSupplierLeads as $lead) {
            SupplierLead::create([
                ...$lead,
                'supermarket_product_id' => $this->record->getKey(),
                'created_by' => auth()->id(),
            ]);
        }

        $this->pendingSupplierLeads = [];
    }
}
