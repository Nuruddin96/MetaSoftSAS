<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SourceOrder;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SourceOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = SourceOrder::with('product')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()->paginate(25)->withQueryString();

        $tenants = Tenant::whereIn('id', $orders->pluck('tenant_id'))->get()->keyBy('id');

        return view('super.source.orders', compact('orders', 'tenants'));
    }

    public function update(Request $request, SourceOrder $order)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,contacted,confirmed,shipped,delivered,cancelled',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $order->update($data);

        return back()->with('success', 'অর্ডার আপডেট হয়েছে।');
    }
}
