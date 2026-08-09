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
        // Also deliberately NOT gating tenant_slug on
        // config('app.tenancy_mode') === 'path': tenant.login only needs
        // tenant_slug when currentTenant is actually bound, and that's
        // already what app()->bound('currentTenant') tells us directly.
        // Adding a tenancy_mode check on top only gave this a second way to
        // fail — e.g. if that config read doesn't come back as 'path' for
        // whatever reason, this used to silently fall through to the
        // parameterless route('tenant.login') call below and reproduce the
        // exact "Missing required parameter [tenant_slug]" error this
        // closure exists to prevent. Passing tenant_slug whenever
        // currentTenant is bound is safe in subdomain mode too — an unused
        // named route() parameter just becomes a harmless query string.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('super.*')) {
                return route('super.login');
            }

            if ($request->routeIs('affiliate.*')) {
                return route('affiliate.login');
            }

            if (app()->bound('currentTenant')) {
                return route('tenant.login', ['tenant_slug' => app('currentTenant')->subdomain]);
            }

            return route('tenant.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
