<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * The single place that knows how to find "the tenant for this request",
 * for both tenancy modes (see App\Http\Middleware\ResolveTenant's
 * docblock). Deliberately a pure lookup — no side effects (no abort,
 * no forgetParameter, no URL::defaults(), no session-cookie config, no
 * binding into the container). Two callers:
 *
 *  - ResolveTenant middleware: calls this, then does its own side effects.
 *  - App\Traits\BelongsToTenant::resolveRouteBinding(): calls this as a
 *    fallback when app()->bound('currentTenant') is still false, because
 *    Laravel's implicit route-model binding (SubstituteBindings, part of
 *    the framework's 'web' middleware group) runs BEFORE resolve.tenant
 *    for tenant-prefixed routes — see BelongsToTenant's docblock for the
 *    full mechanism this exists to close.
 *
 * $request->route()->parameter('tenant_slug') is safe to read before any
 * middleware has run — route parameters are populated by the router
 * itself during matching, which always completes first (the same fact
 * bootstrap/app.php's redirectGuestsTo closure already relies on).
 */
class TenantResolver
{
    public static function fromRequest(Request $request): ?Tenant
    {
        if (config('app.tenancy_mode', 'subdomain') === 'path') {
            $slug = $request->route()?->parameter('tenant_slug');

            return $slug ? Tenant::where('subdomain', strtolower($slug))->first() : null;
        }

        $host = strtolower($request->getHost());
        $central = config('app.central_domain');

        if ($host === $central || $host === 'www.'.$central) {
            return null; // central app — no tenant context
        }

        if (str_ends_with($host, '.'.$central)) {
            return Tenant::where('subdomain', str_replace('.'.$central, '', $host))->first();
        }

        return Tenant::where('custom_domain', $host)->where('custom_domain_verified', true)->first();
    }
}
