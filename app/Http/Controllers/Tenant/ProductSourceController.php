<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SourceOrder;
use App\Models\SourceProduct;
use Illuminate\Http\Request;

class ProductSourceController extends Controller
{
    public function index()
    {
        return view('tenant.product-source.index', [
            'products' => SourceProduct::with('images')->where('is_active', 1)->orderBy('sort_order')->paginate(15),
        ]);
    }

    public function show(SourceProduct $product)
    {
        abort_unless($product->is_active, 404);

        $product->load('images');

        return view('tenant.product-source.show', compact('product'));
    }

    public function order(Request $request, SourceProduct $product)
    {
        $data = $request->validate([
            'quantity'      => 'required|integer|min:1',
            'note'          => 'nullable|string|max:500',
            'contact_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
        ], [
            'contact_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        if ($data['quantity'] < $product->min_order_qty) {
            return back()->withErrors(['quantity' => 'সর্বনিম্ন অর্ডার পরিমাণ ' . $product->min_order_qty . 'টি।']);
        }

        SourceOrder::create([
            'tenant_id'         => app('currentTenant')->id,
            'source_product_id' => $product->id,
            'quantity'          => $data['quantity'],
            'note'              => $data['note'] ?? null,
            'contact_phone'     => $data['contact_phone'],
            'status'            => 'pending',
        ]);

        return back()->with('success', 'অর্ডার পাঠানো হয়েছে — খুব শিগগিরই যোগাযোগ করা হবে।');
    }

    public function myOrders()
    {
        return view('tenant.product-source.orders', [
            'orders' => SourceOrder::withoutGlobalScopes()
                ->where('tenant_id', app('currentTenant')->id)
                ->with('product')->latest()->paginate(20),
        ]);
    }
}
