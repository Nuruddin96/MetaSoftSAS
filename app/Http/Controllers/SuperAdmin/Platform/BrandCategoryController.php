<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\BrandCategory;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Brand categories. A category that brands already use is deactivated
 * (hidden from registration/filters) rather than deleted, so no brand is
 * left without one.
 */
class BrandCategoryController extends Controller
{
    public const ICONS = ['sparkles', 'heart', 'rocket', 'award', 'star', 'layers', 'home', 'newspaper', 'users', 'store', 'globe', 'chart', 'megaphone', 'zap', 'trophy', 'flame'];

    public function index()
    {
        return view('super.platform.categories', [
            'categories' => BrandCategory::ordered()->withCount('brands')->get(),
            'icons' => self::ICONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] = (int) BrandCategory::max('sort_order') + 1;
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('brands/categories', 'public');
        }
        $category = BrandCategory::create($data);
        PlatformAuditLog::record('category.created', $category, ['name' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, BrandCategory $category)
    {
        $data = $this->validated($request, $category);
        $data['is_active'] = $request->boolean('is_active');
        if ($request->hasFile('image')) {
            if ($category->image_path) {
                Storage::disk('public')->delete($category->image_path);
            }
            $data['image_path'] = $request->file('image')->store('brands/categories', 'public');
        }
        $category->fill($data);
        $dirty = $category->getDirty();
        $category->save();
        PlatformAuditLog::record('category.updated', $category, $dirty);

        return back()->with('success', 'Category saved.');
    }

    public function destroy(BrandCategory $category)
    {
        if ($category->brands()->withTrashed()->exists()) {
            $category->update(['is_active' => false]);
            PlatformAuditLog::record('category.deactivated', $category);

            return back()->with('success', 'Brands use this category, so it was deactivated instead of deleted.');
        }

        PlatformAuditLog::record('category.deleted', $category, ['name' => $category->name]);
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    public function reorder(Request $request)
    {
        $order = $request->validate(['order' => 'required|array', 'order.*' => 'integer|min:0|max:9999'])['order'];
        foreach ($order as $id => $position) {
            BrandCategory::whereKey((int) $id)->update(['sort_order' => (int) $position]);
        }
        PlatformAuditLog::record('category.reordered', new BrandCategory, $order);

        return back()->with('success', 'Order saved.');
    }

    private function validated(Request $request, ?BrandCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brand_categories', 'name')->ignore($category?->id)],
            'bn_name' => 'nullable|string|max:100',
            'icon' => ['nullable', Rule::in(self::ICONS)],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:1024',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $n = 2;
        while (BrandCategory::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
