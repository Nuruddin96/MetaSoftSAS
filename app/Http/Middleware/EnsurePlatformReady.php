<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformSchema;
use Closure;
use Illuminate\Http\Request;

/**
 * Recognition-platform routes (brand directory, voting, owner dashboard,
 * Super Admin platform pages) need database/sql/chunk64.sql. Until it's
 * imported they answer with a friendly 503 instead of an SQL error.
 */
class EnsurePlatformReady
{
    public function handle(Request $request, Closure $next)
    {
        if (! PlatformSchema::ready()) {
            return response()->view('central.platform.coming-soon', [], 503);
        }

        return $next($request);
    }
}
