<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;

class ProductController extends Controller
{
    public function index()
    {
        return view('storefront.products', [
            'tenant'     => app('currentTenant'),
            'categories' => Category::where('is_active', 1)->get(),
            'products'   => Product::with('variants')->where('is_active', 1)
                                ->when(request('category'), fn ($q, $slug) =>
                                    $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
                                ->latest()->paginate(24)->withQueryString(),
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::with(['variants' => fn ($q) => $q->where('is_active', 1), 'images'])
            ->where('slug', $slug)->where('is_active', 1)->firstOrFail();

        return view('storefront.product', [
            'tenant'  => app('currentTenant'),
            'product' => $product,
        ]);
    }
}
