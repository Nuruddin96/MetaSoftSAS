<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\DeliveryChargeService;

class ProductController extends Controller
{
    public function index()
    {
        return view('storefront.products', [
            'tenant' => app('currentTenant'),
            'categories' => Category::where('is_active', 1)->get(),
            'products' => Product::with('variants.inventory')->where('is_active', 1)
                ->when(request('category'), fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
                // Same "real, higher reference price" rule as ProductVariant::hasOffer() —
                // backs the homepage offer section's "সব দেখুন" link.
                ->when(request('offer'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('is_active', 1)
                    ->whereNotNull('compare_at_price')
                    ->whereColumn('compare_at_price', '>', 'selling_price')))
                ->latest()->paginate(24)->withQueryString(),
        ]);
    }

    public function show(string $slug, DeliveryChargeService $deliveryCharge)
    {
        $product = Product::with(['variants' => fn ($q) => $q->where('is_active', 1), 'variants.inventory', 'images'])
            ->where('slug', $slug)->where('is_active', 1)->firstOrFail();

        return view('storefront.product', [
            'tenant' => app('currentTenant'),
            'product' => $product,
            // Same source checkout itself charges from — never a second,
            // possibly-stale copy of these two numbers.
            'delivery' => $deliveryCharge->chargesForView(),
        ]);
    }
}
