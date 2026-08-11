<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        return view('super.plans', [
            'plans' => Plan::orderBy('sort_order')->get(),
            'featureList' => config('features.list'),
            'featuresReady' => Plan::featuresColumnReady(),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'max_products' => 'nullable|integer|min:1',
            'max_staff' => 'nullable|integer|min:1',
            'max_warehouses' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
            'features' => 'nullable|array',
            // Never trust arbitrary submitted strings into a JSON column
            // that later gates feature display/access — only keys that
            // actually exist in the fixed catalog are accepted.
            'features.*' => ['string', Rule::in(array_keys(config('features.list')))],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['allow_pos'] = $request->boolean('allow_pos');
        $data['allow_custom_domain'] = $request->boolean('allow_custom_domain');

        // Guarded the same way every other additive-column write in this
        // codebase is (chunk27.sql may not be imported yet on a given
        // environment) — omitting the key entirely (not passing null) is
        // what avoids Eloquent's create()/update() building an "Unknown
        // column" INSERT/UPDATE, same reasoning documented at every
        // attachment_type-style guard elsewhere.
        if (Plan::featuresColumnReady()) {
            $data['features'] = array_values($data['features'] ?? []);
        } else {
            unset($data['features']);
        }

        $plan->update($data);

        return back()->with('success', $plan->name.' আপডেট হয়েছে।');
    }
}
