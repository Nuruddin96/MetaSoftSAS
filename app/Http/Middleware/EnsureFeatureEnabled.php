<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gates a tenant-panel route behind a Plan feature flag (config/features.php
 * catalog, Plan::hasFeature()) so a tenant on a plan without a feature
 * checked in Super Admin can't reach it just by knowing/guessing the URL —
 * chunk27.sql's own comment flagged enforcement as deliberately deferred to
 * a later phase; this middleware is that phase, reusing Plan::hasFeature()
 * exactly as it already exists rather than inventing a second mechanism.
 *
 * hasFeature() already treats a plan with features === null as "nothing
 * enabled" (see Plan::hasFeature()'s docblock, tested by
 * PlanFeatureTest::test_has_feature_false_when_features_column_is_null) —
 * this middleware relies on that same fail-closed semantics rather than
 * special-casing null here, so behavior stays defined in exactly one place.
 * Practical consequence for deployment: any plan Super Admin hasn't
 * explicitly ticked a feature on will block that feature for every tenant
 * on it, including ones who were already connected before this shipped.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $tenant = app('currentTenant');

        if ($tenant->plan?->hasFeature($feature)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'এই ফিচারটি আপনার বর্তমান প্ল্যানে অন্তর্ভুক্ত নেই।'], 403);
        }

        return redirect()->route('tenant.settings')->with(
            'error',
            'এই ফিচারটি আপনার বর্তমান প্ল্যানে অন্তর্ভুক্ত নেই। প্ল্যান আপগ্রেড করুন।'
        );
    }
}
