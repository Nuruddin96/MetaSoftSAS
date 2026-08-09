<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /** Gallery images always live here, separate from the per-tenant thumbnail folder. */
    protected const GALLERY_DIR = 'products/gallery';

    public function index(Request $request)
    {
        $products = Product::with(['variants.inventory', 'category'])
            ->when($request->q, fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->latest()->paginate(20)->withQueryString();

        return view('tenant.products.index', compact('products'));
    }

    public function create()
    {
        return view('tenant.products.form', [
            'product' => null,
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

        $uploadedPaths = [];

        try {
            $product = DB::transaction(function () use ($request, $data, $tenant, &$uploadedPaths) {
                $product = Product::create([
                    'name' => $data['name'],
                    'category_id' => $data['category_id'] ?? null,
                    'description' => $data['description'] ?? null,
                    'has_variants' => count($data['variants']) > 1 ? 1 : 0,
                    'is_active' => 1,
                ]);

                if ($request->hasFile('thumbnail')) {
                    $path = app(ImageOptimizer::class)->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$tenant->id);
                    $uploadedPaths[] = $path;
                    $product->update(['thumbnail_path' => $path]);
                }

                foreach ($request->file('gallery', []) as $i => $file) {
                    $path = app(ImageOptimizer::class)->storeOptimized($file, 'public', self::GALLERY_DIR);
                    $uploadedPaths[] = $path;
                    $product->images()->create(['image_path' => $path, 'sort_order' => $i]);
                }

                $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

                foreach ($data['variants'] as $v) {
                    $variant = $product->variants()->create([
                        'tenant_id' => $tenant->id,
                        'variant_name' => $v['variant_name'] ?: 'Default',
                        'purchase_price' => $v['purchase_price'] ?? 0,
                        'selling_price' => $v['selling_price'],
                        'low_stock_threshold' => $v['low_stock_threshold'] ?? 5,
                    ]);

                    $qty = (int) ($v['stock'] ?? 0);
                    if ($warehouse) {
                        Inventory::create([
                            'variant_id' => $variant->id,
                            'warehouse_id' => $warehouse->id,
                            'quantity' => $qty,
                        ]);
                        if ($qty > 0) {
                            StockMovement::create([
                                'variant_id' => $variant->id,
                                'warehouse_id' => $warehouse->id,
                                'type' => 'purchase',
                                'quantity' => $qty,
                                'reference_type' => 'initial',
                                'user_id' => auth('tenant')->id(),
                            ]);
                        }
                    }
                }

                return $product;
            });
        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            report($e);

            return back()->withInput()->with('error', 'প্রোডাক্ট তৈরি করা যায়নি — কোনো পরিবর্তন সেভ হয়নি। আবার চেষ্টা করুন।');
        }

        return redirect()->route('tenant.products.index')
            ->with('success', 'প্রোডাক্ট যোগ হয়েছে — বারকোড অটো তৈরি হয়ে গেছে।');
    }

    public function edit(Product $product)
    {
        $product->load(['variants.inventory', 'images' => fn ($q) => $q->orderBy('sort_order')]);

        return view('tenant.products.form', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'warehouses' => Warehouse::all(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request);

        $uploadedPaths = [];

        try {
            DB::transaction(function () use ($request, $data, $product, &$uploadedPaths) {
                $product->update([
                    'name' => $data['name'],
                    'category_id' => $data['category_id'] ?? null,
                    'description' => $data['description'] ?? null,
                    'is_active' => $request->boolean('is_active', true),
                ]);

                if ($request->hasFile('thumbnail')) {
                    $path = app(ImageOptimizer::class)->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$product->tenant_id);
                    $uploadedPaths[] = $path;
                    $product->update(['thumbnail_path' => $path]);
                }

                if ($request->hasFile('gallery')) {
                    $next = (int) $product->images()->max('sort_order') + 1;

                    foreach ($request->file('gallery') as $file) {
                        $path = app(ImageOptimizer::class)->storeOptimized($file, 'public', self::GALLERY_DIR);
                        $uploadedPaths[] = $path;
                        $product->images()->create(['image_path' => $path, 'sort_order' => $next++]);
                    }
                }

                // update existing variants' prices (stock changes go through Inventory page)
                foreach ($data['variants'] as $v) {
                    if (! empty($v['id'])) {
                        $product->variants()->where('id', $v['id'])->update([
                            'variant_name' => $v['variant_name'] ?: 'Default',
                            'purchase_price' => $v['purchase_price'] ?? 0,
                            'selling_price' => $v['selling_price'],
                        ]);
                    } else {
                        $product->variants()->create([
                            'tenant_id' => $product->tenant_id,
                            'variant_name' => $v['variant_name'] ?: 'Default',
                            'purchase_price' => $v['purchase_price'] ?? 0,
                            'selling_price' => $v['selling_price'],
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            report($e);

            return back()->withInput()->with('error', 'প্রোডাক্ট আপডেট করা যায়নি — কোনো পরিবর্তন সেভ হয়নি। আবার চেষ্টা করুন।');
        }

        return redirect()->route('tenant.products.index')->with('success', 'প্রোডাক্ট আপডেট হয়েছে।');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return back()->with('success', 'প্রোডাক্ট মুছে ফেলা হয়েছে।');
    }

    /** Delete a single gallery image — file + row. Featured thumbnail is untouched by this. */
    public function destroyImage(ProductImage $image)
    {
        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return back()->with('success', 'গ্যালারি ছবি মুছে ফেলা হয়েছে।');
    }

    /** Persist drag-and-drop gallery order. Every submitted id must belong to this product. */
    public function reorderImages(Request $request, Product $product)
    {
        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|integer',
        ]);

        $ids = $data['order'];

        $owned = $product->images()->whereIn('id', $ids)->pluck('id');

        if ($owned->count() !== count($ids) || $owned->count() !== count(array_unique($ids))) {
            return response()->json(['ok' => false, 'message' => 'অবৈধ ছবি তালিকা।'], 422);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ProductImage::where('id', $id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['ok' => true]);
    }

    protected function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|image|max:4096',
            'gallery' => 'nullable|array|max:8',
            'gallery.*' => 'image|max:4096',
            'variants' => 'required|array|min:1',
            'variants.*.id' => 'nullable|integer',
            'variants.*.variant_name' => 'nullable|string|max:150',
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.selling_price' => 'required|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
        ], [
            'variants.*.selling_price.required' => 'বিক্রয় মূল্য দিতে হবে।',
        ]);
    }
}
