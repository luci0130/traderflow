<?php

namespace App\Modules\CustomerOffers\Filament\Components;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\MarketComparison\Filament\Pages\MarketComparison;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

class OfferProductPicker extends MarketComparison
{
    #[Locked]
    public int $offerId;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(int $offerId): void
    {
        $this->offerId = $offerId;
        $this->authorizeOffer();
    }

    public function hydrate(): void
    {
        $this->authorizeOffer();
    }

    public function render(): View
    {
        return view('filament.customer-offers.offer-product-picker');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function addSelected(): void
    {
        $offer = $this->authorizeOffer();
        app(SupermarketOfferBuilder::class)->appendSelection($offer, $this->selectedSupplierProductIds);
        $this->selectedSupplierProductIds = [];

        Notification::make()->title(__('Products added to offer'))->success()->send();
        $this->dispatch('offer-products-added', offerId: $this->offerId)->to(ItemsRelationManager::class);
    }

    private function authorizeOffer(): CustomerOffer
    {
        $offer = CustomerOfferResource::getEloquentQuery()->findOrFail($this->offerId);
        abort_unless(CustomerOfferResource::canEdit($offer) && ! $offer->isLocked(), 403);

        return $offer;
    }
}
