<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Mirrors Tenant\CategoryController's real capability exactly — that
 * controller (and its blade view, resources/views/tenant/categories.blade.php)
 * only has index/store/destroy: a flat list with a product count, create
 * by name only, and delete with no "in use" guard (products.category_id is
 * ON DELETE SET NULL, so deleting a category just orphans its products'
 * category_id rather than blocking or cascading — confirmed against
 * database/sql/schema.sql). Confirmed directly against routes/web.php
 * (lines 303-305) — no update route exists there at all.
 *
 * `categories.parent_id`/`image_path`/`is_active` all exist as DB columns
 * (database/sql/schema.sql) but the real product never reads, writes, or
 * displays any of them anywhere — no parent()/children() relation on the
 * Category model, no hierarchy rendering in the blade view, no active
 * filter. Deliberately not exposed here either: there is no real backend
 * behavior to mirror for any of them.
 */
class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->latest()->get();

        return response()->json([
            'data' => $categories->map(fn (Category $c) => $this->present($c))->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100']);

        $category = Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(3)),
        ]);

        return response()->json($this->present($category), 201);
    }

    /**
     * Takes a plain int, not an implicit `Category $category` binding —
     * same reason as ProductCatalogController::show()/update(): implicit
     * route-model binding resolves via SubstituteBindings, which runs
     * before this group's own `bind.tenant.token` route middleware, so
     * `app()->bound('currentTenant')` is still false at bind time and
     * Category::resolveRouteBinding() would 404 even for the owning
     * tenant. Explicit tenant_id lookup instead, matching
     * OrderController/CustomerController/ProductCatalogController.
     */
    public function destroy(Request $request, int $category)
    {
        $tenant = app('currentTenant');
        $category = Category::where('tenant_id', $tenant->id)->findOrFail($category);
        $category->delete();

        return response()->json(['ok' => true]);
    }

    protected function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'product_count' => (int) ($category->products_count ?? 0),
        ];
    }
}
