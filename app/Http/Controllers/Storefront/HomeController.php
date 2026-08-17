<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;

class HomeController extends Controller
{
    public function index()
    {
        return view('storefront.home', [
            'tenant' => app('currentTenant'),
            'banners' => Banner::where('is_active', 1)->orderBy('sort_order')->get(),
            'categories' => Category::where('is_active', 1)->limit(12)->get(),
            'featured' => Product::with('variants')->where('is_active', 1)
                ->latest()->limit(8)->get(),
            // Same "real, higher reference price" rule as ProductVariant::hasOffer() —
            // never a fabricated discount, only variants with a genuine compare_at_price.
            // Homepage shows at most 4 (desktop grid-cols-4, mobile grid-cols-2 —
            // see "সব দেখুন" link to the full filtered listing) so there's no
            // need to fetch more here.
            'offers' => Product::with(['variants' => fn ($q) => $q->where('is_active', 1), 'variants.inventory'])
                ->where('is_active', 1)
                ->whereHas('variants', fn ($q) => $q->where('is_active', 1)
                    ->whereNotNull('compare_at_price')
                    ->whereColumn('compare_at_price', '>', 'selling_price'))
                ->latest()->limit(4)->get(),
            'reviews' => Review::tablesReady()
                ? Review::where('is_active', 1)->orderBy('sort_order')->limit(4)->get()
                : collect(),
        ]);
    }
}
