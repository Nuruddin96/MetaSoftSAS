<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;

class DashboardController extends Controller
{
    public function index()
    {
        return view('super.dashboard', [
            'totalTenants'   => Tenant::count(),
            'activeTenants'  => Tenant::where('status', 'active')->count(),
            'trialTenants'   => Tenant::where('status', 'trial')->count(),
            'expiredTenants' => Tenant::whereIn('status', ['expired', 'suspended'])->count(),
            'monthRevenue'   => (float) SubscriptionPayment::where('status', 'completed')
                                    ->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            'totalRevenue'   => (float) SubscriptionPayment::where('status', 'completed')->sum('amount'),
            'totalOrders'    => Order::withoutGlobalScopes()->count(),
            'recentTenants'  => Tenant::latest()->limit(8)->get(),
            'recentPayments' => SubscriptionPayment::where('status', 'completed')->latest('paid_at')->limit(8)->get(),
        ]);
    }
}
