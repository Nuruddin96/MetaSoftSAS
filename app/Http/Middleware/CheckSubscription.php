<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * If subscription/trial expired:
 *  - Storefront shows a "temporarily closed" page
 *  - Tenant admin panel shows ONLY the payment/renew page
 */
class CheckSubscription
{
    /** Routes always allowed even when expired */
    protected array $allowed = [
        'tenant.billing',
        'tenant.billing.pay',
        'tenant.billing.callback',
        'tenant.logout',
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

        // Customer-facing store: show closed page
        if ($request->routeIs('storefront.*')) {
            return response()->view('storefront.closed', ['tenant' => $tenant], 503);
        }

        // Tenant admin: only billing pages
        if ($request->routeIs(...$this->allowed)) {
            return $next($request);
        }

        return redirect()->route('tenant.billing')->with(
            'warning',
            'আপনার সাবস্ক্রিপশনের মেয়াদ শেষ হয়েছে। প্যানেল ব্যবহার করতে পেমেন্ট করুন।'
        );
    }
}
