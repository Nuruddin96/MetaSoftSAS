<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;

/**
 * Global, pre-routing middleware (prepended in bootstrap/app.php — must run
 * before \Illuminate\Session\Middleware\StartSession, not as a route
 * middleware). Only relevant to subdomain tenancy mode.
 *
 * ResolveTenant::resolveBySubdomain() used to set
 * config(['session.cookie' => 'sess_'.$tenant->id]) itself, but that runs as
 * route middleware — by then StartSession has already read the *previous*
 * config('session.cookie') value to decide which cookie to look for on the
 * incoming request. Since SESSION_DOMAIN is the shared parent domain (e.g.
 * .metasoftbd.com), every tenant's browser sends back a cookie named after
 * its own tenant id, but StartSession was looking for the generic default
 * name and never finding it — minting a brand-new anonymous session on
 * every single request. The request only stayed "logged in" because
 * SessionGuard's remember-me recaller re-authenticated it from scratch each
 * time; any request without a valid remember cookie (e.g. right after a
 * login where "remember me" was unchecked) lost its session on the very
 * next request.
 *
 * This is the "separate, pre-existing, never-yet-triggered issue" flagged in
 * ResolveCustomDomain's docblock — never triggered because production is
 * still on TENANCY_MODE=path today, where ResolveTenant's subdomain branch
 * (and this middleware) never runs. Fixing it now, ahead of the planned
 * post-VPS-migration switch to subdomain mode, rather than after tenants
 * start hitting it.
 *
 * Deliberately does not bind the tenant into the container or do anything
 * else ResolveTenant already does — it only needs to run early enough to
 * name the session cookie correctly, and stays a pure lookup otherwise so
 * there's exactly one place (ResolveTenant) that owns "the tenant for this
 * request" as application state. The extra lookup this costs on every
 * subdomain request is the same tradeoff TenantResolver's own docblock
 * already accepts for its other caller (BelongsToTenant::resolveRouteBinding).
 */
class ResolveTenantSessionCookie
{
    public function handle(Request $request, Closure $next)
    {
        if (config('app.tenancy_mode', 'subdomain') !== 'subdomain') {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $central = config('app.central_domain');

        if ($host === $central || $host === 'www.'.$central) {
            return $next($request);
        }

        $tenant = TenantResolver::fromRequest($request);

        if ($tenant) {
            config(['session.cookie' => 'sess_'.$tenant->id]);
        }

        return $next($request);
    }
}
