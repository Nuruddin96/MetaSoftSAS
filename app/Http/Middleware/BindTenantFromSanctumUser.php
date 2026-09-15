<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * The mobile API's equivalent of ResolveTenant — but ResolveTenant itself
 * can't be reused here: it derives tenant from the URL (subdomain/path
 * segment), which doesn't exist for a central API host. This middleware
 * instead derives tenant from the already-authenticated Sanctum user
 * (auth:sanctum MUST run first — see routes/api.php's middleware order),
 * binding `currentTenant` the exact same way ResolveTenant::bind() does so
 * every tenant-scoped model's BelongsToTenant global scope / creating-hook
 * auto-fill behaves identically to the web app.
 *
 * Every mobile API controller additionally re-checks `tenant_id` explicitly
 * on any single-record lookup (never relies on implicit route-model binding
 * alone) — see each controller's doc comments — matching this codebase's
 * own established "never trust a bound model's tenant_id implicitly"
 * defense-in-depth convention (Tenant\CourierController::send(),
 * Tenant\OrderController::complete()).
 */
class BindTenantFromSanctumUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_if(! $user, 401);

        $tenant = $user->tenant;

        abort_if(! $tenant, 404);

        app()->instance('currentTenant', $tenant);

        return $next($request);
    }
}
