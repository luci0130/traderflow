<?php

use App\Http\Controllers\TutorialProgressController;
use App\Models\Tenant;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Http\Middleware\SetLocale;
use App\Support\Tenancy\ActiveTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', function (Request $request, string $locale) {
    $locale = strtolower($locale);

    if (SetLocale::isSupported($locale)) {
        $request->session()->put('locale', $locale);
    }

    return redirect($request->header('referer') ?? '/');
})->name('locale.switch');

Route::redirect('/home', '/')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/')->name('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::post('/tutorials/complete', TutorialProgressController::class)->name('tutorials.complete');

    Route::get('/active-tenant/global', function (Request $request) {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);

        app(ActiveTenant::class)->set(null);
        $request->session()->put(DashboardScope::SESSION_KEY, true);

        return redirect('/');
    })->name('active-tenant.global');

    Route::get('/active-tenant/{tenant}', function (Request $request, Tenant $tenant) {
        abort_unless($tenant->is_active && $request->user()?->canAccessTenant($tenant), 403);

        app(ActiveTenant::class)->set($tenant);
        $request->session()->forget(DashboardScope::SESSION_KEY);

        return redirect('/');
    })->name('active-tenant.switch');
});

require __DIR__.'/settings.php';
