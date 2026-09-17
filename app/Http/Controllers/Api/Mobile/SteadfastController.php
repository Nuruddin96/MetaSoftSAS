<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Courier\CourierManager;

/**
 * Minimal mobile mirror of Tenant\SteadfastCenterController (opened by
 * tapping the Steadfast Balance card on the mobile dashboard, same as
 * the Web dashboard's own Steadfast tile) — live balance (with
 * force-refresh) plus the most recent parcels sent to Steadfast.
 * Deliberately smaller than the Web hub: no search/pagination and no
 * settlement-payments history (Steadfast's own read-only /payments
 * endpoint) — a purpose-built surface for the dashboard tap, not a full
 * port. Reuses the exact same CourierManager/SteadfastService/Order
 * (BelongsToTenant-scoped) stack as the Web controller and the mobile
 * dashboard's own courier-balance tile — nothing here talks to Steadfast
 * or the database in a new way.
 */
class SteadfastController extends Controller
{
    public function show()
    {
        $tenant = app('currentTenant');
        $service = CourierManager::forProvider('steadfast');
        $balance = $service?->cachedBalance($tenant->id);

        return response()->json([
            'active' => (bool) $service,
            'balance' => $balance['balance'] ?? null,
            'balance_updated_at' => $balance['updated_at']?->toIso8601String(),
            'balance_error' => $balance['error'] ?? null,
            'parcels' => $this->recentParcels(),
        ]);
    }

    public function refreshBalance()
    {
        $tenant = app('currentTenant');
        $service = CourierManager::forProvider('steadfast');

        if (! $service) {
            return response()->json(['message' => 'Steadfast API সেটিংস পাওয়া যায়নি — সেটিংস পেজে ক্রেডেনশিয়াল দিন।'], 422);
        }

        $result = $service->cachedBalance($tenant->id, forceRefresh: true);

        if ($result['error']) {
            return response()->json(['message' => 'ব্যালেন্স রিফ্রেশ করা যায়নি: '.$result['error']], 422);
        }

        return response()->json([
            'balance' => $result['balance'],
            'balance_updated_at' => $result['updated_at']?->toIso8601String(),
        ]);
    }

    /** Same base query as SteadfastCenterController's own $parcelsQuery, capped at 20, no search/pagination. */
    private function recentParcels(): array
    {
        return Order::where('courier_provider', 'steadfast')
            ->whereNotNull('courier_consignment_id')
            ->latest()
            ->limit(20)
            ->get(['order_number', 'customer_name', 'courier_status', 'courier_consignment_id', 'total', 'created_at'])
            ->map(fn ($order) => [
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'status' => $order->courier_status,
                'consignment_id' => $order->courier_consignment_id,
                'total' => (float) $order->total,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
