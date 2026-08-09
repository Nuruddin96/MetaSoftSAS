<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;

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
        ]);
    }
}
