<?php

namespace Tests\Feature;

use App\Modules\Support\FeatureModuleServiceProvider;
use App\Providers\Modules\ModuleServiceProvider;
use Tests\TestCase;

class ModuleStructureTest extends TestCase
{
    public function test_module_provider_is_registered_with_the_application(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertContains(ModuleServiceProvider::class, $providers);
        $this->assertTrue($this->app->providerIsLoaded(ModuleServiceProvider::class));
    }

    public function test_configured_modules_have_valid_service_providers(): void
    {
        $modules = config('modules.modules');

        $this->assertCount(19, $modules);

        foreach ($modules as $key => $module) {
            $provider = $module['provider'];

            $this->assertTrue($module['enabled']);
            $this->assertTrue(class_exists($provider), "Provider [{$provider}] does not exist.");
            $this->assertTrue(is_subclass_of($provider, FeatureModuleServiceProvider::class));
            $this->assertSame($key, $provider::key());
            $this->assertSame($module['label'], $provider::label());
        }
    }
}
