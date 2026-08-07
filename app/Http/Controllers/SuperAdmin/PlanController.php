<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('super.plans', ['plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'price_monthly'  => 'required|numeric|min:0',
            'price_yearly'   => 'required|numeric|min:0',
            'max_products'   => 'nullable|integer|min:1',
            'max_staff'      => 'nullable|integer|min:1',
            'max_warehouses' => 'nullable|integer|min:1',
            'is_active'      => 'nullable|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['allow_pos'] = $request->boolean('allow_pos');
        $data['allow_custom_domain'] = $request->boolean('allow_custom_domain');
        $plan->update($data);

        return back()->with('success', $plan->name . ' আপডেট হয়েছে।');
    }
}
