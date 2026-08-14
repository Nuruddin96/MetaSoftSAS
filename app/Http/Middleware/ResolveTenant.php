<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Two tenancy modes, switched by TENANCY_MODE in .env:
 *
 *  path (current, shared-hosting friendly):
 *    - metasoftbd.com                  -> central (landing, register)
 *    - metasoftbd.com/shop/rahim/...   -> tenant storefront + panel
 *
 *  subdomain (after moving to VPS):
 *    - metasoftbd.com                  -> central
 *    - rahim.metasoftbd.com            -> tenant
 *    - customdomain.com                -> tenant (verified)
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (config('app.tenancy_mode', 'subdomain') === 'path') {
            return $this->resolveByPath($request, $next);
        }

        return $this->resolveBySubdomain($request, $next);
    }

    protected function resolveByPath(Request $request, Closure $next)
    {
        $slug = $request->route()?->parameter('tenant_slug');

        if (! $slug) {
            return $next($request); // central routes — no tenant context
        }

        $tenant = TenantResolver::fromRequest($request);

        abort_if(! $tenant, 404, 'Store not found');

        // Controllers never see the slug parameter,
        // and route() calls fill it in automatically.
        $request->route()->forgetParameter('tenant_slug');
        URL::defaults(['tenant_slug' => $tenant->subdomain]);

        $this->bind($tenant);

        return $next($request);
    }

    protected function resolveBySubdomain(Request $request, Closure $next)
    {
        $host = strtolower($request->getHost());
        $central = config('app.central_domain');

        if ($host === $central || $host === 'www.'.$central) {
            return $next($request); // central app
        }

        $tenant = TenantResolver::fromRequest($request);

        abort_if(! $tenant, 404, 'Store not found');

        // Per-tenant session cookie naming (subdomain mode) now happens in
        // App\Http\Middleware\ResolveTenantSessionCookie, which is prepended
        // ahead of routing/StartSession instead of running here as route
        // middleware — setting config('session.cookie') at this point is too
        // late for StartSession to have used it on this request. See that
        // middleware's docblock for the full mechanism.
        $this->bind($tenant);

        return $next($request);
    }

    protected function bind(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
        config(['app.name' => $tenant->store_name]);
    }
}
