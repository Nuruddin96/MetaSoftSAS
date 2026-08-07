<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = SubscriptionPayment::latest()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->paginate(30)->withQueryString();

        $tenants = Tenant::whereIn('id', $payments->pluck('tenant_id'))->get()->keyBy('id');

        return view('super.payments', compact('payments', 'tenants'));
    }
}
