<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CourierSetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\IncompleteOrder;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();
        $tenant = app('currentTenant');

        $checklist = [
            'product' => Product::exists(),
            'logo' => (bool) $tenant->logo_path,
            'courier' => CourierSetting::where('is_active', 1)->exists(),
            'order' => Order::exists(),
        ];

        $lowStockCount = (int) DB::table('product_variants as pv')
            ->leftJoin('inventory as i', 'i.variant_id', '=', 'pv.id')
            ->where('pv.tenant_id', $tenant->id)
            ->select('pv.id')
            ->groupBy('pv.id', 'pv.low_stock_threshold')
            ->havingRaw('COALESCE(SUM(i.quantity), 0) <= pv.low_stock_threshold')
            ->get()->count();

        $newMessages = MessengerMessage::where('status', 'new')->where('direction', 'in')->count();
        $newIncomplete = IncompleteOrder::where('status', 'abandoned')->count();

        $districtStats = Order::whereNotIn('orders.status', ['cancelled'])
            ->whereNotNull('district_id')
            ->join('bd_districts', 'orders.district_id', '=', 'bd_districts.id')
            ->selectRaw('bd_districts.bn_name as name, COUNT(*) as orders')
            ->groupBy('bd_districts.id', 'bd_districts.bn_name')
            ->orderByDesc('orders')
            ->get();

        return view('tenant.dashboard', [
            'checklist' => $checklist,
            'lowStockCount' => $lowStockCount,
            'newMessages' => $newMessages,
            'newIncomplete' => $newIncomplete,
            'tenant' => app('currentTenant'),
            'todayOrders' => Order::where('created_at', '>=', $today)->count(),
            'todaySales' => (float) Order::where('created_at', '>=', $today)
                ->whereNotIn('status', ['cancelled', 'returned'])->sum('total'),
            'pendingOrders' => Order::where('status', 'pending')->count(),
            'totalProducts' => Product::count(),
            'totalCustomers' => Customer::count(),
            // Orders actually handed to a courier and not yet resolved —
            // NOT a product-catalog count. courier_status is only refreshed
            // when staff manually clicks "refresh" (CourierController) and
            // Pathao's refresh is a no-op stub, so courier_consignment_id +
            // the app-level order status (which every delivered/cancelled/
            // returned order does get moved to) is the reliable signal.
            'courierPendingCount' => Order::whereNotNull('courier_consignment_id')
                ->whereNotIn('status', ['delivered', 'cancelled', 'returned'])
                ->count(),
            'todayExpenses' => (float) Expense::whereDate('expense_date', $today)->sum('amount'),
            'recentOrders' => Order::latest()->paginate(10),
            'byChannel' => Order::selectRaw('channel, COUNT(*) as c')->groupBy('channel')->pluck('c', 'channel'),
            'topDistricts' => $districtStats->take(5),
            'moreDistrictsCount' => max(0, $districtStats->count() - 5),
        ]);
    }
}
