<?php

namespace App\Providers\Modules;

use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        foreach (config('modules.modules', []) as $module) {
            if (($module['enabled'] ?? true) !== true) {
                continue;
            }

            $provider = $module['provider'] ?? null;

            if (is_string($provider) && class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }
}
