<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
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

        $tenant = Tenant::where('subdomain', strtolower($slug))->first();

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

        $tenant = null;

        if (str_ends_with($host, '.'.$central)) {
            $subdomain = str_replace('.'.$central, '', $host);
            $tenant = Tenant::where('subdomain', $subdomain)->first();
        } else {
            $tenant = Tenant::where('custom_domain', $host)
                ->where('custom_domain_verified', true)
                ->first();
        }

        abort_if(! $tenant, 404, 'Store not found');

        // Separate session cookie per tenant only needed in subdomain mode
        config(['session.cookie' => 'sess_'.$tenant->id]);

        $this->bind($tenant);

        return $next($request);
    }

    protected function bind(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
        config(['app.name' => $tenant->store_name]);
    }
}
