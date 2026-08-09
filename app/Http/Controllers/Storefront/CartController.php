<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartController extends Controller
{
    protected function key(): string
    {
        return 'cart_'.app('currentTenant')->id;
    }

    public function index()
    {
        $cart = session($this->key(), []);
        $variants = ProductVariant::with('product')->whereIn('id', array_keys($cart))->get();

        $items = $variants->map(fn ($v) => [
            'variant' => $v,
            'qty' => $cart[$v->id],
            'total' => $cart[$v->id] * $v->selling_price,
        ]);

        return view('storefront.cart', [
            'tenant' => app('currentTenant'),
            'items' => $items,
            'subtotal' => $items->sum('total'),
        ]);
    }

    public function add(Request $request)
    {
        $data = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'qty' => 'nullable|integer|min:1|max:100',
        ]);

        $cart = session($this->key(), []);
        $cart[$data['variant_id']] = ($cart[$data['variant_id']] ?? 0) + ($data['qty'] ?? 1);
        session([$this->key() => $cart]);

        return redirect()->route('storefront.cart')->with('success', 'কার্টে যোগ হয়েছে।');
    }

    public function update(Request $request)
    {
        $cart = [];
        foreach ((array) $request->input('qty', []) as $variantId => $qty) {
            $qty = (int) $qty;
            if ($qty > 0) {
                $cart[(int) $variantId] = min($qty, 100);
            }
        }
        session([$this->key() => $cart]);

        return redirect()->route('storefront.cart')->with('success', 'কার্ট আপডেট হয়েছে।');
    }

    public function remove(Request $request, int $variantId)
    {
        $cart = session($this->key(), []);
        unset($cart[$variantId]);
        session([$this->key() => $cart]);

        return redirect()->route('storefront.cart')->with('success', 'প্রোডাক্টটি কার্ট থেকে সরানো হয়েছে।');
    }

    public function clear()
    {
        session()->forget($this->key());

        return redirect()->route('storefront.cart')->with('success', 'কার্ট খালি করা হয়েছে।');
    }
}
