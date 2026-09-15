<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * The MetaSoft Connector plugin's equivalent of BindTenantFromSanctumUser.
 * auth:sanctum authenticates the plugin's bearer token to a
 * WordPressConnection row (its `tokenable`, not a User) — this middleware
 * binds `currentTenant` from that row so every tenant-scoped model's
 * BelongsToTenant global scope behaves identically to the web app and the
 * mobile API. Must run after auth:sanctum — see routes/api.php.
 */
class BindTenantFromWordPressConnection
{
    public function handle(Request $request, Closure $next)
    {
        $connection = $request->user();

        abort_if(! $connection, 401);

        $tenant = $connection->tenant;

        abort_if(! $tenant, 404);

        if ($connection->status !== 'connected') {
            abort(403, 'এই WordPress সংযোগটি সক্রিয় নয়।');
        }

        app()->instance('currentTenant', $tenant);

        return $next($request);
    }
}
