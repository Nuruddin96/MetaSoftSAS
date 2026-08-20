<?php

namespace App\Services\Api;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the metrics Tenant\DashboardController::index() already computes
 * (todayOrders/Sales, pendingOrders, courierPendingCount, totalCustomers,
 * todayExpenses, lowStockCount, byChannel, topDistricts) — same queries,
 * same definitions (see that controller's inline comments for why each is
 * shaped the way it is, e.g. courierPendingCount using
 * courier_consignment_id + non-terminal status rather than courier_status).
 * Deliberately omits checklist/newMessages/newIncomplete/totalProducts —
 * not part of the Flutter Dashboard screen's scope.
 */
class DashboardSummaryService
{
    public function summary(int $tenantId): array
    {
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
            'by_channel' => Order::where('tenant_id', $tenantId)
                ->selectRaw('channel, COUNT(*) as c')->groupBy('channel')->pluck('c', 'channel'),
            'top_districts' => $districtStats->take(5)->map(fn ($d) => [
                'district_name' => $d->name,
                'order_count' => (int) $d->orders,
            ])->values(),
            'more_districts_count' => max(0, $districtStats->count() - 5),
            'recent_orders' => Order::where('tenant_id', $tenantId)->with('items')->latest()->limit(10)->get(),
        ];
    }
}
