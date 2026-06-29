<?php

namespace App\Modules\NumberSequences;

use App\Models\Tenant;
use App\Modules\NumberSequences\Services\NumberSequenceGenerator;
use App\Modules\Support\FeatureModuleServiceProvider;

final class NumberSequencesServiceProvider extends FeatureModuleServiceProvider
{
    public const KEY = 'number_sequences';

    public const LABEL = 'Serii de numerotare';

    public function boot(): void
    {
        // Seed the default sequence set whenever a new tenant is created.
        Tenant::created(function (Tenant $tenant): void {
            app(NumberSequenceGenerator::class)->ensureDefaultsFor($tenant->getKey());
        });
    }
}
