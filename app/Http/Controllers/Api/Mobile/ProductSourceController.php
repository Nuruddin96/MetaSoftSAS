<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\SourceOrder;
use App\Models\SourceProduct;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\ProductSourceController's real capability: index/show
 * (browse the platform-wide sourcing catalog — SourceProduct has NO
 * tenant_id at all, "Super-admin managed sourcing catalog (global)" per
 * database/sql/chunk8.sql, so it is correctly NOT tenant-scoped here
 * either), order (place a purchase request), myOrders (this tenant's own
 * placed orders). No update/cancel/detail-by-id for orders — the web panel
 * has none either (resources/views/tenant/product-source/orders.blade.php
 * is read-only). SourceOrder has no BelongsToTenant trait (no automatic
 * global scope), so every order query below filters `tenant_id` manually,
 * matching the web controller's own approach exactly.
 */
class ProductSourceController extends Controller
{
    public function index(Request $request)
    {
        $products = SourceProduct::with('images')->where('is_active', 1)->orderBy('sort_order')->paginate(15);

        return response()->json([
            'data' => $products->getCollection()->map(fn (SourceProduct $p) => $this->presentSummary($p))->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(SourceProduct $sourceProduct)
    {
        abort_unless($sourceProduct->is_active, 404);
        $sourceProduct->load('images');

        return response()->json($this->presentDetail($sourceProduct));
    }

    public function order(Request $request, SourceProduct $sourceProduct)
    {
        abort_unless($sourceProduct->is_active, 404);

        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'note' => 'nullable|string|max:500',
            'contact_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
        ], [
            'contact_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        if ($data['quantity'] < $sourceProduct->min_order_qty) {
            return response()->json([
                'message' => 'সর্বনিম্ন অর্ডার পরিমাণ '.$sourceProduct->min_order_qty.'টি।',
                'errors' => ['quantity' => ['সর্বনিম্ন অর্ডার পরিমাণ '.$sourceProduct->min_order_qty.'টি।']],
            ], 422);
        }

        $order = SourceOrder::create([
            'tenant_id' => app('currentTenant')->id,
            'source_product_id' => $sourceProduct->id,
            'quantity' => $data['quantity'],
            'note' => $data['note'] ?? null,
            'contact_phone' => $data['contact_phone'],
            'status' => 'pending',
        ]);

        $order->setRelation('product', $sourceProduct);

        return response()->json($this->presentOrder($order), 201);
    }

    public function myOrders(Request $request)
    {
        $tenant = app('currentTenant');

        $orders = SourceOrder::where('tenant_id', $tenant->id)->with('product')->latest()->paginate(20);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (SourceOrder $o) => $this->presentOrder($o))->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    protected function presentSummary(SourceProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'unit_price' => (float) $p->unit_price,
            'max_price' => $p->max_price !== null ? (float) $p->max_price : null,
            'min_order_qty' => (int) $p->min_order_qty,
            'thumbnail_url' => $this->thumbnailUrl($p),
        ];
    }

    protected function presentDetail(SourceProduct $p): array
    {
        return $this->presentSummary($p) + [
            'description' => $p->description,
            'delivery_time_days' => $p->delivery_time_days,
            'shipping_cost' => (float) $p->shipping_cost,
            'images' => $p->images->map(fn ($img) => asset('storage/'.$img->image_path))->values()->all(),
        ];
    }

    /** Gallery image first, falling back to the product's own image_path — same priority resources/views/tenant/product-source/index.blade.php uses. */
    protected function thumbnailUrl(SourceProduct $p): ?string
    {
        $path = $p->images->first()?->image_path ?? $p->image_path;

        return $path ? asset('storage/'.$path) : null;
    }

    protected function presentOrder(SourceOrder $o): array
    {
        return [
            'id' => $o->id,
            'product_name' => $o->product?->name,
            'quantity' => $o->quantity,
            'status' => $o->status,
            'admin_note' => $o->admin_note,
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }
}
