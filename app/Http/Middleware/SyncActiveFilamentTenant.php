<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\ActiveTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function setPermissionsTeamId;

class SyncActiveFilamentTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            app(ActiveTenant::class)->set($tenant);
            setPermissionsTeamId($tenant->getKey());
        }

        return $next($request);
    }
}
