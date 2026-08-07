<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.tenant'     => \App\Http\Middleware\ResolveTenant::class,
            'check.subscription' => \App\Http\Middleware\CheckSubscription::class,
        ]);

        // Must run before routing (not a route middleware) so a verified
        // custom-domain request can be rewritten to the existing /shop/{slug}
        // shape before the router ever tries to match it.
        $middleware->prepend(\App\Http\Middleware\ResolveCustomDomain::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
