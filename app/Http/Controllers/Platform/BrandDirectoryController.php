<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Support\Platform\BdLocations;
use Illuminate\Http\Request;

/** Public brand directory (/brands) and brand profile (/brand/{slug}) — approved brands only. */
class BrandDirectoryController extends Controller
{
    public function index(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $category = BrandCategory::active()->where('slug', (string) $request->query('category'))->first();
        $division = in_array($request->query('division'), BdLocations::divisions(), true) ? $request->query('division') : null;

        $brands = Brand::published()->with(Brand::CARD_RELATIONS)
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")
                ->orWhere('district', 'like', "%{$q}%")->orWhere('founder_name', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")->orWhere('sub_category', 'like', "%{$q}%")))
            ->when($category, fn ($w) => $w->where('brand_category_id', $category->id))
            ->when($division, fn ($w) => $w->where('division', $division))
            ->when($request->boolean('verified'), fn ($w) => $w->where('is_verified', true))
            // Paid placement first (always labelled "Sponsored"), then editorial picks, then newest.
            ->orderByRaw('CASE WHEN is_sponsored = 1 AND (sponsored_until IS NULL OR sponsored_until >= ?) THEN 0 ELSE 1 END', [now()->toDateString()])
            ->orderByDesc('is_featured')->orderBy('featured_order')->latest('approved_at')
            ->paginate(24)->withQueryString();

        return view('central.platform.brands', [
            'brands' => $brands,
            'q' => $q,
            'category' => $category,
            'division' => $division,
            'categories' => BrandCategory::active()->ordered()->withCount(['brands' => fn ($b) => $b->published()])->get(),
            'divisions' => BdLocations::divisions(),
        ]);
    }

    public function show(string $slug)
    {
        $brand = Brand::published()->where('slug', $slug)->with('category')->firstOrFail();
        Brand::whereKey($brand->id)->increment('views_count');

        $entries = $brand->voteEntries()->where('is_active', true)->with('campaign', 'category')
            ->whereHas('campaign', fn ($c) => $c->visible())->get()
            ->filter(fn ($e) => $e->campaign->isOpen())->values();

        return view('central.platform.brand', [
            'brand' => $brand,
            'badges' => $brand->badges(),
            'entries' => $entries,
            'recognitions' => $brand->recognitions()->with('award', 'category')->latest()->get(),
            'finalists' => $brand->nominations()->whereIn('status', ['finalist', 'shortlisted'])->with('award', 'category')->latest()->get(),
            'related' => Brand::published()->where('id', '!=', $brand->id)->where('brand_category_id', $brand->brand_category_id)->with(Brand::CARD_RELATIONS)->inRandomOrder()->take(4)->get(),
        ]);
    }
}
