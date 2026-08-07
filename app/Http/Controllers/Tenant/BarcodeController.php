<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Product;

class BarcodeController extends Controller
{
    public function print(Product $product)
    {
        $product->load('variants');

        return view('tenant.products.barcodes', compact('product'));
    }
}
