<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Courier\CourierManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dashboard-style hub for the Steadfast integration, opened by clicking the
 * Steadfast Balance tile on the tenant dashboard. Reuses the existing
 * SteadfastService/CourierManager/Order (BelongsToTenant-scoped) stack —
 * nothing here talks to Steadfast or the database in a new way, it only
 * surfaces what CourierController/SteadfastService already do.
 *
 * No "request payment/settlement" action exists here on purpose: Steadfast's
 * public API (portal.packzy.com/api/v1) only exposes GET /payments (a
 * read-only settlement history — see SteadfastService::getPayments()), not a
 * POST endpoint to submit a payout/withdrawal request. Building a form for
 * that would either silently fail or have to fake success, so it is left
 * unimplemented rather than faked — see PROJECT_KNOWLEDGE.md-style note in
 * the view's "পেমেন্ট রিকোয়েস্ট" card.
 */
class SteadfastCenterController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');
        $service = CourierManager::forProvider('steadfast');

        $balance = $service ? $service->cachedBalance($tenant->id) : null;

        $parcelsQuery = Order::where('courier_provider', 'steadfast')
            ->whereNotNull('courier_consignment_id');

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $parcelsQuery->where(function ($q) use ($term) {
                $q->where('order_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_phone', 'like', "%{$term}%")
                    ->orWhere('courier_consignment_id', 'like', "%{$term}%")
                    ->orWhere('courier_tracking_code', 'like', "%{$term}%");
            });
        }

        $parcels = $parcelsQuery->latest()->paginate(15)->withQueryString();

        // Overview + COD figures come entirely from our own orders table
        // (courier_status is only ever written by CourierController::send()/
        // refreshStatus()) — no separate "list all consignments" endpoint
        // exists on Steadfast's API to cross-check against, so this is the
        // authoritative local view, not an estimate.
        $base = fn () => Order::where('courier_provider', 'steadfast')->whereNotNull('courier_consignment_id');

        $overview = [
            'total' => (clone $base())->count(),
            'delivered' => (clone $base())->whereIn('courier_status', ['delivered', 'partial_delivered'])->count(),
            'processing' => (clone $base())->whereNotIn('courier_status', ['delivered', 'partial_delivered', 'cancelled'])->count(),
            'cancelled' => (clone $base())->where('courier_status', 'cancelled')->count(),
        ];

        $codBase = fn () => (clone $base())->where('payment_method', 'cod');
        $cod = [
            'total' => (float) (clone $codBase())->sum('total'),
            'delivered' => (float) (clone $codBase())->whereIn('courier_status', ['delivered', 'partial_delivered'])->sum('total'),
            'pending' => (float) (clone $codBase())->whereNotIn('courier_status', ['delivered', 'partial_delivered', 'cancelled'])->sum('total'),
        ];

        $payments = [];
        $paymentsError = null;
        if ($service) {
            try {
                $payments = $service->getPayments();
            } catch (\Throwable $e) {
                $paymentsError = Str::limit($e->getMessage(), 120);
            }
        }

        return view('tenant.steadfast.index', [
            'steadfastActive' => (bool) $service,
            'balance' => $balance,
            'parcels' => $parcels,
            'overview' => $overview,
            'cod' => $cod,
            'payments' => $payments,
            'paymentsError' => $paymentsError,
            'searchTerm' => $request->q,
        ]);
    }

    public function refreshBalance()
    {
        $service = CourierManager::forProvider('steadfast');

        if (! $service) {
            return back()->with('error', 'Steadfast API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।');
        }

        $result = $service->cachedBalance(app('currentTenant')->id, forceRefresh: true);

        if ($result['error']) {
            return back()->with('error', 'ব্যালেন্স রিফ্রেশ করা যায়নি: '.Str::limit($result['error'], 120));
        }

        return back()->with('success', 'ব্যালেন্স রিফ্রেশ হয়েছে।');
    }
}
