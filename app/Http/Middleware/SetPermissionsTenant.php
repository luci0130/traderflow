<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function setPermissionsTeamId;

class SetPermissionsTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            // Roles are global (tenant_id = null): permissions apply regardless of
            // the active tenant, so the permission team is always null. The active
            // tenant only scopes document data (see ActiveTenant / ScopesToActiveTenant),
            // not authorization.
            setPermissionsTeamId(null);
        }

        return $next($request);
    }
}
