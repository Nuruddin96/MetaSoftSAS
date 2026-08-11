<?php

use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'check.subscription' => CheckSubscription::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);

        // Must run before routing (not a route middleware) so a verified
        // custom-domain request can be rewritten to the existing /shop/{slug}
        // shape before the router ever tries to match it.
        $middleware->prepend(ResolveCustomDomain::class);

        // This app has no route named "login" — it has three, one per guard
        // (tenant.login, super.login, affiliate.login). Without this,
        // Authenticate::redirectTo() returns null on an unauthenticated
        // request, and Laravel's default exception handler falls back to a
        // hard-coded route('login'), which throws RouteNotFoundException
        // instead of redirecting. Picking by the matched route's name prefix
        // matches this app's own tenant./super./affiliate. naming
        // convention (see routes/web.php).
        //
        // Deliberately NOT relying on URL::defaults() to fill tenant.login's
        // {tenant_slug} automatically: Illuminate\Routing\UrlGenerator::
        // setRequest() discards its cached RouteUrlGenerator (and whatever
        // URL::defaults() had set) whenever the bound `request` is rebound,
        // which empirically happens between resolve.tenant setting that
        // default and this closure running — confirmed by test, not assumed.
        // Reading app('currentTenant') directly (bound by resolve.tenant
        // before auth:tenant ever runs, same as everywhere else in this
        // codebase) and passing tenant_slug explicitly sidesteps that
        // entirely, since an explicitly-named route() parameter never
        // consults defaultParameters in the first place.
        //
        // $request->route('tenant_slug') is preferred over app('currentTenant')
        // (bound by resolve.tenant): it comes straight from route matching,
        // which always completes before any middleware runs, so it's
        // populated here even if resolve.tenant hasn't executed yet — unlike
        // currentTenant, which depends on resolve.tenant having already run
        // (order not guaranteed against auth:tenant once Laravel's
        // $middlewarePriority sorting gets involved). currentTenant is kept
        // as a second-choice fallback for the (normally unreachable) case
        // where the route has no tenant_slug segment at all — e.g. subdomain
        // tenancy mode — but resolve.tenant bound a tenant anyway.
        //
        // Never call route('tenant.login') without a resolved slug — that's
        // exactly the "Missing required parameter" exception this closure
        // exists to prevent. If no tenant context can be determined at all,
        // fall back to a plain URL instead of the named route, since
        // route('tenant.login') has no parameterless form.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('super.*')) {
                return route('super.login');
            }

            if ($request->routeIs('affiliate.*')) {
                return route('affiliate.login');
            }

            $tenantSlug = $request->route('tenant_slug');

            if (! $tenantSlug && app()->bound('currentTenant')) {
                $tenant = app('currentTenant');
                $tenantSlug = $tenant->subdomain ?? $tenant->slug ?? null;
            }

            if ($tenantSlug) {
                return route('tenant.login', ['tenant_slug' => $tenantSlug]);
            }

            return url('/login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
