<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SubscriptionPayment;

/**
 * Mirrors Tenant\BillingController::index()'s real capability — current
 * plan/subscription status, usage against the one confirmed plan limit
 * (`max_products`, see Tenant::isWithinLimit()'s only real caller), all
 * plans for comparison, and payment history. Deliberately READ-ONLY: no
 * pay()/callback() mirror here — bKash/SSLCommerz are hosted-redirect
 * gateways that don't make sense embedded in a mobile app, so `payment_url`
 * just points at the tenant's existing, proven web billing page for the
 * tenant to complete payment there (same external-checkout pattern the
 * workflow spec calls for), not a new payment system.
 */
class BillingController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');

        $plans = Plan::where('is_active', 1)->orderBy('sort_order')->get();
        $currentPlan = $tenant->plan_id ? $plans->firstWhere('id', $tenant->plan_id) ?? Plan::find($tenant->plan_id) : null;

        return response()->json([
            'status' => $tenant->status,
            'trial_ends_at' => optional($tenant->trial_ends_at)->toIso8601String(),
            'subscription_ends_at' => optional($tenant->subscription_ends_at)->toIso8601String(),
            'current_plan' => $currentPlan ? $this->presentPlan($currentPlan) : null,
            'usage' => [
                'products' => [
                    'used' => Product::count(),
                    'limit' => $currentPlan->max_products ?? null,
                ],
            ],
            'plans' => $plans->map(fn (Plan $p) => $this->presentPlan($p)),
            'payments' => SubscriptionPayment::where('tenant_id', $tenant->id)->latest()->limit(10)->get()
                ->map(fn (SubscriptionPayment $p) => [
                    'id' => $p->id,
                    'gateway' => $p->gateway,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'trx_id' => $p->trx_id,
                    'created_at' => optional($p->created_at)->toIso8601String(),
                ]),
            'payment_url' => $tenant->url().'/panel/billing',
        ]);
    }

    protected function presentPlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price_monthly' => (float) $plan->price_monthly,
            'price_yearly' => (float) $plan->price_yearly,
            'max_products' => $plan->max_products,
            'max_staff' => $plan->max_staff,
            'max_warehouses' => $plan->max_warehouses,
            'allow_pos' => (bool) $plan->allow_pos,
            'allow_custom_domain' => (bool) $plan->allow_custom_domain,
        ];
    }
}
