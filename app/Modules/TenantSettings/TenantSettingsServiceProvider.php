<?php

namespace App\Modules\TenantSettings;

use App\Modules\Support\FeatureModuleServiceProvider;

final class TenantSettingsServiceProvider extends FeatureModuleServiceProvider
{
    public const KEY = 'tenant_settings';

    public const LABEL = 'Setari tenant';
}
