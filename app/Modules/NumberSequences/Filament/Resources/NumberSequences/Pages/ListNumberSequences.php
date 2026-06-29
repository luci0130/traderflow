<?php

namespace App\Modules\NumberSequences\Filament\Resources\NumberSequences\Pages;

use App\Modules\NumberSequences\Filament\Resources\NumberSequences\NumberSequenceResource;
use App\Modules\NumberSequences\Services\NumberSequenceGenerator;
use App\Support\Tenancy\ActiveTenant;
use Filament\Resources\Pages\ListRecords;

class ListNumberSequences extends ListRecords
{
    protected static string $resource = NumberSequenceResource::class;

    public function mount(): void
    {
        parent::mount();

        // Make sure the active tenant always has the full set of sequences listed.
        $tenantId = app(ActiveTenant::class)->id();

        if ($tenantId !== null) {
            app(NumberSequenceGenerator::class)->ensureDefaultsFor($tenantId);
        }
    }
}
