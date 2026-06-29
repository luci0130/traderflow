<?php

namespace App\Modules\Producers\Filament\Pages;

use App\Modules\Producers\Models\SupplierProduct;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ProducerDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -2;

    protected string $view = 'producers.filament.pages.producer-dashboard';

    public function getTitle(): string|Htmlable
    {
        return __('Dashboard');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Welcome, :name', ['name' => auth()->user()?->name ?? '']);
    }

    /**
     * @return array{total: int, active: int, expired: int}
     */
    public function getStats(): array
    {
        $producerId = auth()->user()?->producer_id;

        if ($producerId === null) {
            return ['total' => 0, 'active' => 0, 'expired' => 0];
        }

        $rows = SupplierProduct::query()
            ->where('producer_id', $producerId)
            ->get(['status', 'valid_until']);

        return [
            'total' => $rows->count(),
            'active' => $rows->filter(fn (SupplierProduct $p): bool => $p->is_offer_valid)->count(),
            'expired' => $rows->reject(fn (SupplierProduct $p): bool => $p->is_offer_valid)->count(),
        ];
    }
}
