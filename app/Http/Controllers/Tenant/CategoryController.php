<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'image' => 'nullable|image|max:4096',
        ]);

        Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(3)),
            'parent_id' => $data['parent_id'] ?? null,
            'image_path' => $request->hasFile('image')
                ? app(ImageOptimizer::class)->storeOptimized($request->file('image'), 'public', 'categories/'.$tenant->id)
                : null,
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
            'image' => 'nullable|image|max:4096',
            'remove_image' => 'sometimes|boolean',
        ]);

        if (($data['parent_id'] ?? null) !== null && $category->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['এই ক্যাটাগরির নিজস্ব সাব-ক্যাটাগরি আছে, তাই এটিকে অন্য কোনো ক্যাটাগরির সাব-ক্যাটাগরি বানানো যাবে না।'],
            ]);
        }

        if ($request->hasFile('image')) {
            if ($category->image_path) {
                Storage::disk('public')->delete($category->image_path);
            }
            $data['image_path'] = app(ImageOptimizer::class)->storeOptimized($request->file('image'), 'public', 'categories/'.$tenant->id);
        } elseif ($request->boolean('remove_image')) {
            if ($category->image_path) {
                Storage::disk('public')->delete($category->image_path);
            }
            $data['image_path'] = null;
        }
        unset($data['remove_image'], $data['image']);

        $category->update($data);

        return back()->with('success', 'ক্যাটাগরি আপডেট হয়েছে।');
    }

    public function destroy(Category $category)
    {
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }
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
