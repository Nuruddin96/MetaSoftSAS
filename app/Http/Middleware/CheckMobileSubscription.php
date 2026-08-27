<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Mobile API's equivalent of CheckSubscription — that middleware can't be
 * reused directly here: it branches on named web routes
 * (`$request->routeIs('tenant.billing', ...)`) and redirects/renders a
 * Blade view, neither of which exists on this stateless JSON API (see
 * BindTenantFromSanctumUser's docblock for why the API generally can't
 * reuse the web tenant-resolution middleware either). This mirrors the
 * exact same expiry computation and the same tenant->status='expired'
 * side effect, but responds with 402 JSON instead of a redirect, and
 * allowlists by URI path instead of route name since API routes here are
 * unnamed.
 *
 * Must run after bind.tenant.token (needs app('currentTenant') bound) —
 * see routes/api.php's middleware order.
 */
class CheckMobileSubscription
{
    /**
     * Always reachable even when expired — same intent as
     * CheckSubscription's $allowed, minus the web-only pay/callback
     * routes. Full path including the `api/` prefix Laravel's default
     * api routing adds (confirmed via `php artisan route:list`) — Request::
     * is() matches against the full path, not the route-group-relative one.
     */
    protected array $allowed = [
        'api/mobile/v1/billing',
        'api/mobile/v1/auth/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = app('currentTenant');

        $expired = match ($tenant->status) {
            'trial' => $tenant->trial_ends_at?->isPast() ?? true,
            'active' => $tenant->subscription_ends_at?->isPast() ?? true,
            default => true, // expired, suspended
        };

        if (! $expired) {
            return $next($request);
        }

        if (! in_array($tenant->status, ['suspended', 'expired'])) {
            $tenant->update(['status' => 'expired']);
        }

        if ($request->is(...$this->allowed)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'আপনার সাবস্ক্রিপশনের মেয়াদ শেষ হয়েছে। অ্যাপ ব্যবহার চালিয়ে যেতে পেমেন্ট করুন।',
            'code' => 'subscription_expired',
        ], 402);
    }
}
