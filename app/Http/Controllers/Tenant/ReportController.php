<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected function range(Request $request): array
    {
        $from = $request->date('from') ?: now()->startOfMonth();
        $to = ($request->date('to') ?: now())->endOfDay();

        return [$from, $to];
    }

    public function sales(Request $request)
    {
        [$from, $to] = $this->range($request);

        $base = Order::whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'returned']);

        $daily = (clone $base)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('d')->orderBy('d')->get();

        return view('tenant.reports.sales', [
            'from' => $from, 'to' => $to,
            'daily' => $daily,
            'orders' => (clone $base)->count(),
            'revenue' => (float) (clone $base)->sum('total'),
            'avg' => (float) (clone $base)->avg('total'),
            'byStatus' => Order::whereBetween('created_at', [$from, $to])
                ->selectRaw('status, COUNT(*) as c, SUM(total) as t')
                ->groupBy('status')->get(),
            'bySource' => (clone $base)->selectRaw('source, COUNT(*) as c, SUM(total) as t')
                ->groupBy('source')->get(),
        ]);
    }

    public function profitLoss(Request $request)
    {
        [$from, $to] = $this->range($request);

        $delivered = Order::whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'returned']);

        $revenue = (float) (clone $delivered)->sum('total');
        $shipping = (float) (clone $delivered)->sum('delivery_charge');
        $orderIds = (clone $delivered)->pluck('id');

        $cogs = (float) OrderItem::whereIn('order_id', $orderIds)
            ->selectRaw('SUM(purchase_price * quantity) as c')->value('c');

        $expenses = (float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount');

        $expenseBreakdown = Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')->with('category')->get();

        $grossProfit = $revenue - $shipping - $cogs;
        $netProfit = $grossProfit - $expenses;

        return view('tenant.reports.profit-loss', compact(
            'from', 'to', 'revenue', 'shipping', 'cogs', 'expenses', 'expenseBreakdown', 'grossProfit', 'netProfit'
        ));
    }

    public function locations(Request $request)
    {
        [$from, $to] = $this->range($request);

        $byDivision = Order::whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled'])
            ->join('bd_divisions', 'orders.division_id', '=', 'bd_divisions.id')
            ->selectRaw('bd_divisions.bn_name as name, COUNT(*) as orders, SUM(orders.total) as revenue')
            ->groupBy('bd_divisions.id', 'bd_divisions.bn_name')
            ->orderByDesc('orders')->get();

        $byDistrict = Order::whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled'])
            ->join('bd_districts', 'orders.district_id', '=', 'bd_districts.id')
            ->selectRaw('bd_districts.bn_name as name, COUNT(*) as orders, SUM(orders.total) as revenue')
            ->groupBy('bd_districts.id', 'bd_districts.bn_name')
            ->orderByDesc('orders')->limit(25)->get();

        return view('tenant.reports.locations', compact('from', 'to', 'byDivision', 'byDistrict'));
    }

    public function products(Request $request)
    {
        [$from, $to] = $this->range($request);

        $top = OrderItem::whereHas('order', fn ($q) => $q
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'returned']))
            ->selectRaw('product_name, variant_name, SUM(quantity) as qty, SUM(line_total) as revenue,
                         SUM((unit_price - purchase_price) * quantity) as profit')
            ->groupBy('product_name', 'variant_name')
            ->orderByDesc('qty')->limit(30)->get();

        return view('tenant.reports.products', compact('from', 'to', 'top'));
    }
}
