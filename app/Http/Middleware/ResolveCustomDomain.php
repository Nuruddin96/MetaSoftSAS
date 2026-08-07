<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Global, pre-routing middleware (prepended in bootstrap/app.php — must run
 * before the router, not as a route middleware). If the current host is a
 * tenant's verified custom domain, rewrites the request's path to the
 * internal /shop/{slug}/... shape the existing routes already understand —
 * reusing them verbatim, no new/duplicate route registrations.
 *
 * Deliberately does NOT touch the request's Host. Leaving it alone means
 * route()/url() generation keeps producing URLs on the custom domain for the
 * rest of this request's lifecycle, with zero extra work — that's what
 * actually keeps the domain in the address bar. (The /shop/{slug} segment
 * still appears in generated links after this milestone; stripping it is a
 * separate, later piece.)
 *
 * Only active in path tenancy mode — subdomain mode already resolves custom
 * domains natively inside ResolveTenant::resolveBySubdomain().
 */
class ResolveCustomDomain
{
    public function handle(Request $request, Closure $next)
    {
        if (config('app.tenancy_mode', 'subdomain') !== 'path') {
            return $next($request);
        }

        $host    = strtolower($request->getHost());
        $central = config('app.central_domain');

        if ($host === $central || $host === 'www.' . $central) {
            return $next($request);
        }

        $tenant = Tenant::where('custom_domain', $host)
            ->where('custom_domain_verified', true)
            ->first();

        if (! $tenant) {
            return $next($request);
        }

        $prefixedPath = '/shop/' . $tenant->subdomain . $request->getPathInfo();
        $queryString  = $request->getQueryString();
        $requestUri   = $prefixedPath . ($queryString ? '?' . $queryString : '');

        $rewritten = $request->duplicate(
            server: array_merge($request->server->all(), [
                'REQUEST_URI' => $requestUri,
                'PATH_INFO'   => $prefixedPath,
            ])
        );

        // Flag for later milestones (session-cookie scoping, URL stripping) —
        // cheaper than re-querying for the tenant a second time downstream.
        $rewritten->attributes->set('resolved_custom_domain', $host);

        app()->instance('request', $rewritten);

        return $next($rewritten);
    }
}
