<?php

namespace App\Services\Api;

use App\Models\CourierSetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\IncompleteOrder;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Advertising\AdvertisingBalanceService;
use App\Services\Courier\CourierManager;
use App\Services\Courier\SteadfastService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the metrics Tenant\DashboardController::index() already computes
 * (todayOrders/Sales, pendingOrders, courierPendingCount, totalCustomers,
 * todayExpenses, lowStockCount, byChannel, topDistricts) — same queries,
 * same definitions (see that controller's inline comments for why each is
 * shaped the way it is, e.g. courierPendingCount using
 * courier_consignment_id + non-terminal status rather than courier_status).
 * Also mirrors checklist/newMessages/newIncomplete/totalProducts — added
 * for Priority 3 parity (Flutter's own dashboard_screen.dart docblock
 * already documented this exact gap).
 */
class DashboardSummaryService
{
    public function __construct(protected AdvertisingBalanceService $advertising) {}

    public function summary(Tenant $tenant): array
    {
        $tenantId = $tenant->id;
        $today = now()->startOfDay();

        $lowStockCount = (int) DB::table('product_variants as pv')
            ->leftJoin('inventory as i', 'i.variant_id', '=', 'pv.id')
            ->where('pv.tenant_id', $tenantId)
            ->select('pv.id')
            ->groupBy('pv.id', 'pv.low_stock_threshold')
            ->havingRaw('COALESCE(SUM(i.quantity), 0) <= pv.low_stock_threshold')
            ->get()->count();

        $districtStats = Order::where('orders.tenant_id', $tenantId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereNotNull('district_id')
            ->join('bd_districts', 'orders.district_id', '=', 'bd_districts.id')
            ->selectRaw('bd_districts.bn_name as name, COUNT(*) as orders')
            ->groupBy('bd_districts.id', 'bd_districts.bn_name')
            ->orderByDesc('orders')
            ->get();

        return [
            'today_orders' => Order::where('tenant_id', $tenantId)->where('created_at', '>=', $today)->count(),
            'today_sales' => (float) Order::where('tenant_id', $tenantId)->where('created_at', '>=', $today)
                ->whereNotIn('status', ['cancelled', 'returned'])->sum('total'),
            'pending_orders' => Order::where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'courier_pending_count' => Order::where('tenant_id', $tenantId)->whereNotNull('courier_consignment_id')
                ->whereNotIn('status', ['delivered', 'cancelled', 'returned'])
                ->count(),
            'total_customers' => Customer::where('tenant_id', $tenantId)->count(),
            'today_expenses' => (float) Expense::where('tenant_id', $tenantId)->whereDate('expense_date', $today)->sum('amount'),
            'low_stock_count' => $lowStockCount,
            // Cast to object: `pluck('c','channel')->toArray()` is `[]` (a
            // plain empty PHP array) for a tenant with zero orders — e.g.
            // right after onboarding completes — and json_encode() serializes
            // an empty array as JSON `[]`, not `{}`, even though every
            // non-empty result already serializes correctly as an object
            // (PHP string-keyed arrays always do). The mobile client's
            // DashboardSummary.fromJson always casts this field to
            // `Map<String, dynamic>?`, so an inconsistently-shaped empty
            // response crashed it with a type-cast error. `(object)` forces
            // `{}` in both the empty and non-empty cases.
            'by_channel' => (object) Order::where('tenant_id', $tenantId)
                ->selectRaw('channel, COUNT(*) as c')->groupBy('channel')->pluck('c', 'channel')->toArray(),
            'top_districts' => $districtStats->take(5)->map(fn ($d) => [
                'district_name' => $d->name,
                'order_count' => (int) $d->orders,
            ])->values(),
            'more_districts_count' => max(0, $districtStats->count() - 5),
            'recent_orders' => Order::where('tenant_id', $tenantId)->with('items')->latest()->limit(10)->get(),
            'checklist' => [
                'product' => Product::where('tenant_id', $tenantId)->exists(),
                'logo' => (bool) $tenant->logo_path,
                'courier' => CourierSetting::where('tenant_id', $tenantId)->where('is_active', 1)->exists(),
                'order' => Order::where('tenant_id', $tenantId)->exists(),
            ],
            'new_messages' => MessengerMessage::where('tenant_id', $tenantId)
                ->where('status', 'new')->where('direction', 'in')->count(),
            'new_incomplete' => IncompleteOrder::where('tenant_id', $tenantId)->where('status', 'abandoned')->count(),
            'total_products' => Product::where('tenant_id', $tenantId)->count(),
            // Mobile-only addition (dashboard parity pass) — null for any
            // tenant the advertising module isn't enabled for, same
            // isEnabled() gate AdvertisingController itself uses; the
            // client must treat null as "hide", never as ৳0 spent.
            'advertising_balance' => $this->advertising->isEnabled($tenant)
                ? (($balance = $this->advertising->balance($tenant->id)) !== null ? (float) $balance : null)
                : null,
            // "Add Cost" dashboard widget — the sum of today's per-order
            // additional_amount entries (see chunk55.sql / the New Order
            // "অতিরিক্ত খরচ" field), not a separate feature/table of its own.
            'today_additional_cost' => (float) Order::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $today)->sum('additional_amount'),
            'courier_balances' => $this->courierBalances($tenant),
        ];
    }

    /**
     * Only Steadfast has a real, confirmed balance endpoint in this
     * codebase (Pathao's status-lookup was already documented elsewhere as
     * unavailable — CourierDispatchService::refreshStatus()'s own doc
     * comment — and no balance endpoint for it is used/verified anywhere,
     * so it's deliberately never attempted here rather than guessed at).
     * A live Graph-style API call on every dashboard load would be slow
     * and rate-limit-risky, so this is cached briefly per tenant; a fetch
     * failure (bad credentials, network) degrades to "just don't show it"
     * rather than a broken dashboard — never a fabricated ৳0.
     */
    protected function courierBalances(Tenant $tenant): array
    {
        $service = CourierManager::forProvider('steadfast');
        if (! $service instanceof SteadfastService) {
            return [];
        }

        $cacheKey = "courier_balance_steadfast_{$tenant->id}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return [['provider' => 'steadfast', 'balance' => (float) $cached]];
        }

        try {
            $balance = $service->getBalance();
        } catch (\Throwable $e) {
            // Never cache a failure — a transient network/credential blip
            // shouldn't hide a real balance for the full cache window; the
            // next dashboard load just tries again.
            return [];
        }

        Cache::put($cacheKey, $balance, now()->addMinutes(15));

        return [['provider' => 'steadfast', 'balance' => (float) $balance]];
    }
}
