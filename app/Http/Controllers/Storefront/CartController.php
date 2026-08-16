<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * variant_id is validated against THIS tenant only — the plain
     * exists:product_variants,id rule this used to have runs a raw query
     * against the table directly (Laravel's exists rule never goes through
     * Eloquent, so BelongsToTenant's global scope never applied to it),
     * meaning any other tenant's real variant id would have passed
     * validation. Stock is re-checked here too — the storefront UI already
     * disables the buy button for an out-of-stock variant, but that is a
     * frontend courtesy only; this is the actual enforcement point.
     */
    public function add(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', app('currentTenant')->id)],
            'qty' => 'nullable|integer|min:1|max:100',
        ]);

        $variant = ProductVariant::findOrFail($data['variant_id']);
        $stock = $variant->totalStock();

        if ($stock <= 0) {
            return back()->with('error', 'দুঃখিত, এই প্রোডাক্টটি এখন স্টকে নেই।');
        }

        $cart = session($this->key(), []);
        $already = $cart[$variant->id] ?? 0;
        $qty = min($data['qty'] ?? 1, max(0, $stock - $already));

        if ($qty <= 0) {
            return back()->with('error', 'দুঃখিত, স্টকে যতটুকু আছে তার পুরোটাই ইতিমধ্যে আপনার কার্টে আছে।');
        }

        $cart[$variant->id] = $already + $qty;
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
