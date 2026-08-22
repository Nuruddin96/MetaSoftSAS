<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * `categories.parent_id`/`image_path`/`is_active` have existed as DB
 * columns since before this feature but were never read/written anywhere.
 * Subcategory support below reuses `parent_id` as-is — no schema change.
 * Deliberately capped at TWO levels: `parent_id` must reference one of the
 * tenant's own TOP-LEVEL categories, enforced by [parentIdRule]'s
 * `whereNull('parent_id')` clause — mirrors Api\Mobile\CategoryController's
 * identical rule.
 */
class CategoryController extends Controller
{
    public function index()
    {
        return view('tenant.categories', [
            'categories' => Category::withCount('products')->with('children')->whereNull('parent_id')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'parent_id' => $this->parentIdRule($tenant->id),
        ]);

        Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(3)),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        return back()->with('success', 'ক্যাটাগরি যোগ হয়েছে।');
    }

    /** New — no update route existed for categories before this. */
    public function update(Request $request, Category $category)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'parent_id' => $this->parentIdRule($tenant->id, excludeId: $category->id),
            'is_active' => 'sometimes|boolean',
        ]);

        if (($data['parent_id'] ?? null) !== null && $category->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['এই ক্যাটাগরির নিজস্ব সাব-ক্যাটাগরি আছে, তাই এটিকে অন্য কোনো ক্যাটাগরির সাব-ক্যাটাগরি বানানো যাবে না।'],
            ]);
        }

        $category->update($data);

        return back()->with('success', 'ক্যাটাগরি আপডেট হয়েছে।');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return back()->with('success', 'ক্যাটাগরি মুছে ফেলা হয়েছে।');
    }

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
}
