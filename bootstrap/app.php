<?php

use App\Http\Middleware\CheckSubscription;
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
        // Also deliberately NOT reading tenant_slug from app('currentTenant')
        // (bound by resolve.tenant): that binding is unreliable at this
        // point because Laravel sorts middleware by $middlewarePriority,
        // which can run auth:tenant (and therefore this redirect) before
        // resolve.tenant regardless of their order in the route group —
        // confirmed by production still hitting the parameterless
        // route('tenant.login') fallback below with currentTenant never
        // bound. $request->route('tenant_slug') doesn't have this problem:
        // it comes straight from route matching, which always completes
        // before any middleware runs, so it's populated here even when
        // resolve.tenant hasn't executed yet.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('super.*')) {
                return route('super.login');
            }

            if ($request->routeIs('affiliate.*')) {
                return route('affiliate.login');
            }

            if ($request->route('tenant_slug')) {
                return route('tenant.login', ['tenant_slug' => $request->route('tenant_slug')]);
            }

            return route('tenant.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
