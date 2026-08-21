<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\ReportController's real capability exactly (sales,
 * profit-loss, locations, top-products — all date-range based), returning
 * JSON instead of a Blade view. A fully-built backend, not invented for
 * mobile.
 */
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

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => (clone $base)->count(),
            'revenue' => (float) (clone $base)->sum('total'),
            'avg' => (float) ((clone $base)->avg('total') ?? 0),
            'daily' => $daily->map(fn ($r) => ['date' => $r->d, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue]),
            'by_status' => Order::whereBetween('created_at', [$from, $to])
                ->selectRaw('status, COUNT(*) as c, SUM(total) as t')
                ->groupBy('status')->get()
                ->map(fn ($r) => ['status' => $r->status, 'orders' => (int) $r->c, 'revenue' => (float) $r->t]),
            'by_source' => (clone $base)->selectRaw('source, COUNT(*) as c, SUM(total) as t')
                ->groupBy('source')->get()
                ->map(fn ($r) => ['source' => $r->source, 'orders' => (int) $r->c, 'revenue' => (float) $r->t]),
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

        $cogs = (float) (OrderItem::whereIn('order_id', $orderIds)
            ->selectRaw('SUM(purchase_price * quantity) as c')->value('c') ?? 0);

        $expenses = (float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount');

        $expenseBreakdown = Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')->with('category')->get()
            ->map(fn ($r) => ['category_name' => $r->category?->name, 'total' => (float) $r->total]);

        $grossProfit = $revenue - $shipping - $cogs;
        $netProfit = $grossProfit - $expenses;

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => $revenue,
            'shipping' => $shipping,
            'cogs' => $cogs,
            'expenses' => $expenses,
            'expense_breakdown' => $expenseBreakdown,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
        ]);
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

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_division' => $byDivision->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue]),
            'by_district' => $byDistrict->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue]),
        ]);
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

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'top' => $top->map(fn ($r) => [
                'product_name' => $r->product_name,
                'variant_name' => $r->variant_name,
                'qty' => (int) $r->qty,
                'revenue' => (float) $r->revenue,
                'profit' => (float) $r->profit,
            ]),
        ]);
    }
}
