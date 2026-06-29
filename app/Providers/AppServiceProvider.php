<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Behind an HTTPS tunnel/proxy (e.g. ngrok) the connection to the app is
        // plain HTTP, so asset URLs would be generated as http and blocked as
        // mixed content. Force https early — before panel providers register and
        // bake their asset URLs via Vite::asset().
        if (request()->server('HTTP_X_FORWARDED_PROTO') === 'https') {
            URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSuperAdminGate();
        $this->configureModulePolicyDiscovery();
        $this->configureFilamentTutorials();
    }

    /**
     * Register the guided-tutorial (Driver.js) script and expose the current
     * user's completed tutorials to JavaScript on every Filament panel page.
     */
    protected function configureFilamentTutorials(): void
    {
        FilamentAsset::register([
            Js::make('tutorials', Vite::asset('resources/js/filament-tours.js'))->module(),
        ]);

        // Script data depends on routes/auth, so register it only while a
        // panel is actually serving a request (not during console commands).
        Filament::serving(function (): void {
            FilamentAsset::registerScriptData([
                'tutorials' => [
                    'completed' => auth()->user()?->completedTutorials() ?? [],
                    'completeUrl' => route('tutorials.complete'),
                    'csrf' => csrf_token(),
                ],
            ]);
        });
    }

    /**
     * Resolve module policies under app/Modules/{Module}/Policies/{Model}Policy,
     * which Laravel's default convention does not discover automatically.
     */
    protected function configureModulePolicyDiscovery(): void
    {
        Gate::guessPolicyNamesUsing(function (string $modelClass): array {
            $candidates = [];

            if (str_starts_with($modelClass, 'App\\Modules\\')) {
                $candidates[] = str_replace('\\Models\\', '\\Policies\\', $modelClass).'Policy';
            }

            $base = class_basename($modelClass);
            $candidates[] = 'App\\Policies\\'.$base.'Policy';

            return $candidates;
        });
    }

    /**
     * Grant unrestricted access to users assigned the super_admin role
     * in any tenant. Runs before any other gate or policy check.
     */
    protected function configureSuperAdminGate(): void
    {
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
