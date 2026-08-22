<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors Tenant\CategoryController's real capability — index/store/destroy
 * plus (new) update, a flat list with a product count, create/edit by name
 * (+ optional `parent_id` for a subcategory), and delete with no "in use"
 * guard (products.category_id is ON DELETE SET NULL, so deleting a category
 * just orphans its products' category_id rather than blocking or cascading
 * — confirmed against database/sql/schema.sql).
 *
 * `categories.parent_id`/`image_path`/`is_active` have existed as DB
 * columns since before this feature but were never read/written anywhere
 * (see the git history on this file). Subcategory support below reuses
 * `parent_id` as-is — no schema change needed. Deliberately capped at TWO
 * levels (a subcategory can never itself have a parent_id pointing at
 * another subcategory): `parent_id` must reference one of the tenant's own
 * TOP-LEVEL categories, enforced by the `whereNull('parent_id')` clause in
 * [parentIdRule]. `image_path` is still not exposed — no upload endpoint
 * exists for it and adding one is out of scope for this pass.
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
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'parent_id' => $this->parentIdRule($tenant->id),
        ]);

        $category = Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(3)),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        return response()->json($this->present($category), 201);
    }

    /**
     * New — the real backend never had an update route for categories at
     * all (see class docblock). Added additively; index/store/destroy are
     * completely untouched.
     */
    public function update(Request $request, int $category)
    {
        $tenant = app('currentTenant');
        $category = Category::where('tenant_id', $tenant->id)->findOrFail($category);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'parent_id' => [
                'sometimes',
                ...$this->parentIdRule($tenant->id, excludeId: $category->id),
            ],
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $category->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['এই ক্যাটাগরির নিজস্ব সাব-ক্যাটাগরি আছে, তাই এটিকে অন্য কোনো ক্যাটাগরির সাব-ক্যাটাগরি বানানো যাবে না।'],
            ]);
        }

        $category->update($data);

        return response()->json($this->present($category->fresh()));
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

    /**
     * `parent_id` must be null or one of the SAME tenant's own top-level
     * categories (parent_id IS NULL on the target row) — this single rule
     * enforces tenant isolation, existence, and the two-level cap all at
     * once. [excludeId] additionally blocks a category from being set as
     * its own parent on update.
     */
    private function parentIdRule(int $tenantId, ?int $excludeId = null): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('categories', 'id')->where(function ($query) use ($tenantId, $excludeId) {
                $query->where('tenant_id', $tenantId)->whereNull('parent_id');
                if ($excludeId !== null) {
                    $query->where('id', '!=', $excludeId);
                }
            }),
        ];
    }

    protected function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'product_count' => (int) ($category->products_count ?? 0),
            'parent_id' => $category->parent_id,
            'is_active' => (bool) $category->is_active,
        ];
    }
}
