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
 * reusing them verbatim, no new/duplicate route registrations — and makes
 * the session cookie for this request host-only, since a domain scoped to
 * .{central_domain} can never be honored by an unrelated apex domain.
 *
 * Deliberately does NOT touch the request's Host. Leaving it alone means
 * route()/url() generation keeps producing URLs on the custom domain for the
 * rest of this request's lifecycle, with zero extra work — that's what
 * actually keeps the domain in the address bar. (The /shop/{slug} segment
 * still appears in generated links after this milestone; stripping it is a
 * separate, later piece.)
 *
 * Only active in path tenancy mode — subdomain mode already resolves custom
 * domains natively inside ResolveTenant::resolveBySubdomain() (that method
 * still doesn't vary session.domain itself; see the M5b commit for why that
 * gap is untouched here — it's a separate, pre-existing, never-yet-triggered
 * issue, not something this milestone is scoped to fix).
 */
class ResolveCustomDomain
{
    public function handle(Request $request, Closure $next)
    {
        if (config('app.tenancy_mode', 'subdomain') !== 'path') {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $central = config('app.central_domain');

        if ($host === $central || $host === 'www.'.$central) {
            return $next($request);
        }

        $tenant = Tenant::where('custom_domain', $host)
            ->where('custom_domain_verified', true)
            ->first();

        if (! $tenant) {
            return $next($request);
        }

        // Host-only session/remember-me/CSRF cookies for THIS masked request
        // only — SESSION_DOMAIN (e.g. .metasoftbd.com) can never legally be
        // set for an unrelated apex domain like this one; leaving it in place
        // would make the browser silently drop the cookie, breaking cart,
        // CSRF, and panel login. This runs before StartSession resolves the
        // CookieJar, so session, remember-me, and CSRF cookies alike pick up
        // the override. Every other request path (central, www, subdomain
        // mode, unrecognized hosts) returns above and never reaches this line
        // — config('session.domain') is untouched for them.
        config(['session.domain' => null]);

        $prefixedPath = '/shop/'.$tenant->subdomain.$request->getPathInfo();
        $queryString = $request->getQueryString();
        $requestUri = $prefixedPath.($queryString ? '?'.$queryString : '');

        $rewritten = $request->duplicate(
            server: array_merge($request->server->all(), [
                'REQUEST_URI' => $requestUri,
                'PATH_INFO' => $prefixedPath,
            ])
        );

        // Flag for later milestones (session-cookie scoping, URL stripping) —
        // cheaper than re-querying for the tenant a second time downstream.
        $rewritten->attributes->set('resolved_custom_domain', $host);

        app()->instance('request', $rewritten);

        return $next($rewritten);
    }
}
