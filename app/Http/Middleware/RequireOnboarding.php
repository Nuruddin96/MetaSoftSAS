<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Redirects a tenant that hasn't finished the onboarding wizard to it,
 * instead of dropping them into an empty dashboard — same
 * "check-and-redirect on every panel request, with an explicit allowlist"
 * shape as CheckSubscription, applied right alongside it on the same
 * ['auth:tenant', 'check.subscription', 'require.onboarding'] route group.
 *
 * A tenant that existed before this feature shipped was backfilled with
 * onboarding_completed_at already set (database/sql/chunk52.sql), so
 * Tenant::needsOnboarding() is false for them from the moment this ships —
 * this middleware never interrupts an existing tenant's panel session.
 */
class RequireOnboarding
{
    /** Routes reachable even while onboarding is incomplete — the wizard itself, plus the same "must always work" routes CheckSubscription allows. */
    protected array $allowed = [
        'tenant.onboarding.*',
        'tenant.logout',
        'tenant.billing',
        'tenant.billing.pay',
        'tenant.billing.callback',
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = app('currentTenant');

        if (! $tenant->needsOnboarding() || $request->routeIs(...$this->allowed)) {
            return $next($request);
        }

        return redirect()->route('tenant.onboarding.redirect');
    }
}
