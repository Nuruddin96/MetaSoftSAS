<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Minimal search-only slice for Create Order's product/variant picker —
 * NOT the full Products feature (Phase 3 scope per
 * docs/existing-project-analysis.md §7). Flagged as an addition beyond the
 * literal endpoint list in this task's instructions: Create Order (in
 * scope) cannot add any line item without it, and it was endpoint #18
 * (MUST HAVE) in the already-approved API plan.
 *
 * purchase_price is deliberately never selected — staff-only/cost field,
 * per the existing AI-knowledge-service allow-list convention
 * (existing-project-analysis.md §9).
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');
        $q = $request->string('q')->toString();

        $rows = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('inventory as i', 'i.variant_id', '=', 'pv.id')
            ->where('pv.tenant_id', $tenant->id)
            ->where('pv.is_active', 1)
            ->where('p.is_active', 1)
            ->when($q, fn ($query) => $query->where(fn ($qq) => $qq
                ->where('p.name', 'like', "%{$q}%")
                ->orWhere('pv.variant_name', 'like', "%{$q}%")
                ->orWhere('pv.sku', 'like', "%{$q}%")))
            ->groupBy('pv.id', 'p.name', 'pv.variant_name', 'pv.sku', 'pv.selling_price')
            ->orderBy('p.name')
            ->limit(50)
            ->get([
                'pv.id as variant_id',
                'p.name as product_name',
                'pv.variant_name',
                'pv.sku',
                'pv.selling_price',
                DB::raw('COALESCE(SUM(i.quantity), 0) as stock_quantity'),
            ]);

        return response()->json(['data' => $rows]);
    }
}
