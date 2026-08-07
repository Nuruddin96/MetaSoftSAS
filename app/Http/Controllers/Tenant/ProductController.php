<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['variants.inventory', 'category'])
            ->when($request->q, fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
            ->latest()->paginate(20)->withQueryString();

        return view('tenant.products.index', compact('products'));
    }

    public function create()
    {
        return view('tenant.products.form', [
            'product'    => null,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::all(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $tenant->isWithinLimit('max_products', Product::count())) {
            return back()->withInput()->with('error',
                'আপনার প্ল্যানের প্রোডাক্ট লিমিট শেষ। প্ল্যান আপগ্রেড করুন।');
        }

        $data = $this->validateProduct($request);

        $product = Product::create([
            'name'         => $data['name'],
            'category_id'  => $data['category_id'] ?? null,
            'description'  => $data['description'] ?? null,
            'has_variants' => count($data['variants']) > 1 ? 1 : 0,
            'is_active'    => 1,
        ]);

        if ($request->hasFile('thumbnail')) {
            $product->update([
                'thumbnail_path' => $request->file('thumbnail')->store('products/' . $tenant->id, 'public'),
            ]);
        }

        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

        foreach ($data['variants'] as $v) {
            $variant = $product->variants()->create([
                'tenant_id'      => $tenant->id,
                'variant_name'   => $v['variant_name'] ?: 'Default',
                'purchase_price' => $v['purchase_price'] ?? 0,
                'selling_price'  => $v['selling_price'],
                'low_stock_threshold' => $v['low_stock_threshold'] ?? 5,
            ]);

            $qty = (int) ($v['stock'] ?? 0);
            if ($warehouse) {
                Inventory::create([
                    'variant_id'   => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity'     => $qty,
                ]);
                if ($qty > 0) {
                    StockMovement::create([
                        'variant_id'   => $variant->id,
                        'warehouse_id' => $warehouse->id,
                        'type'         => 'purchase',
                        'quantity'     => $qty,
                        'reference_type' => 'initial',
                        'user_id'      => auth('tenant')->id(),
                    ]);
                }
            }
        }

        return redirect()->route('tenant.products.index')
            ->with('success', 'প্রোডাক্ট যোগ হয়েছে — বারকোড অটো তৈরি হয়ে গেছে।');
    }

    public function edit(Product $product)
    {
        $product->load('variants.inventory');

        return view('tenant.products.form', [
            'product'    => $product,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::all(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        $product->update([
            'name'        => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active'   => $request->boolean('is_active', true),
        ]);

        if ($request->hasFile('thumbnail')) {
            $product->update([
                'thumbnail_path' => $request->file('thumbnail')->store('products/' . $product->tenant_id, 'public'),
            ]);
        }

        // update existing variants' prices (stock changes go through Inventory page)
        foreach ($data['variants'] as $v) {
            if (! empty($v['id'])) {
                $product->variants()->where('id', $v['id'])->update([
                    'variant_name'   => $v['variant_name'] ?: 'Default',
                    'purchase_price' => $v['purchase_price'] ?? 0,
                    'selling_price'  => $v['selling_price'],
                ]);
            } else {
                $product->variants()->create([
                    'tenant_id'      => $product->tenant_id,
                    'variant_name'   => $v['variant_name'] ?: 'Default',
                    'purchase_price' => $v['purchase_price'] ?? 0,
                    'selling_price'  => $v['selling_price'],
                ]);
            }
        }

        return redirect()->route('tenant.products.index')->with('success', 'প্রোডাক্ট আপডেট হয়েছে।');
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return back()->with('success', 'প্রোডাক্ট মুছে ফেলা হয়েছে।');
    }

    protected function validateProduct(Request $request): array
    {
        return $request->validate([
            'name'                       => 'required|string|max:255',
            'category_id'                => 'nullable|exists:categories,id',
            'description'                => 'nullable|string',
            'thumbnail'                  => 'nullable|image|max:4096',
            'variants'                   => 'required|array|min:1',
            'variants.*.id'              => 'nullable|integer',
            'variants.*.variant_name'    => 'nullable|string|max:150',
            'variants.*.purchase_price'  => 'nullable|numeric|min:0',
            'variants.*.selling_price'   => 'required|numeric|min:0',
            'variants.*.stock'           => 'nullable|integer|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
        ], [
            'variants.*.selling_price.required' => 'বিক্রয় মূল্য দিতে হবে।',
        ]);
    }
}
