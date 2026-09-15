<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors Tenant\WebsiteController's banner slice only (index/storeBanner/
 * destroyBanner) — the mobile Storefront Settings feature covers just
 * homepage banners for now, not the wider WebsiteController surface
 * (pages, reviews, homepage/footer text), which stays web-panel-only.
 */
class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::orderBy('sort_order')->get();

        return response()->json(['data' => $banners->map(fn (Banner $b) => $this->present($b))->all()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'image' => 'required|image|max:4096',
            'title' => 'nullable|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'button_text' => 'nullable|string|max:50',
            'button_link' => 'nullable|string|max:255',
        ]);

        $tenant = app('currentTenant');
        $path = app(ImageOptimizer::class)->storeOptimized($request->file('image'), 'public', 'banners/'.$tenant->id);

        $banner = Banner::create([
            'image_path' => $path,
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'button_text' => $data['button_text'] ?? null,
            'button_link' => $data['button_link'] ?? null,
            'sort_order' => (int) Banner::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return response()->json($this->present($banner), 201);
    }

    public function destroy(int $banner)
    {
        $banner = Banner::where('tenant_id', app('currentTenant')->id)->findOrFail($banner);

        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $banner->delete();

        return response()->json(['ok' => true]);
    }

    protected function present(Banner $banner): array
    {
        return [
            'id' => $banner->id,
            'image_url' => $banner->image_path ? asset('storage/'.$banner->image_path) : null,
            'title' => $banner->title,
            'subtitle' => $banner->subtitle,
            'button_text' => $banner->button_text,
            'button_link' => $banner->button_link,
            'sort_order' => $banner->sort_order,
            'is_active' => (bool) $banner->is_active,
        ];
    }
}
