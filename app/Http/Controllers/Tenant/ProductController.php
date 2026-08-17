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
                        'compare_at_price' => $v['compare_at_price'] ?? null,
                        'attributes' => $this->attributesFromInput($v),
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
        $tenant = app('currentTenant');

        try {
            DB::transaction(function () use ($request, $data, $product, $tenant, &$uploadedPaths) {
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

                // Existing variants: price/attribute changes only — stock for
                // an ALREADY-EXISTING variant still goes through the
                // Inventory page (its own audited adjust()/StockMovement
                // trail), never overwritten here.
                //
                // Bug fix: a variant added on this exact edit screen (no
                // ->id yet) used to be created with no Inventory row at
                // all — stockCount()/totalStock() would return 0 forever,
                // and checkout's decrement() would silently affect zero
                // rows. New variants now get the same Inventory (+
                // StockMovement, when a real starting quantity was given)
                // treatment store() already gives every variant on initial
                // product creation.
                $warehouse = null;

                foreach ($data['variants'] as $v) {
                    if (! empty($v['id'])) {
                        $product->variants()->where('id', $v['id'])->update([
                            'variant_name' => $v['variant_name'] ?: 'Default',
                            'purchase_price' => $v['purchase_price'] ?? 0,
                            'selling_price' => $v['selling_price'],
                            'compare_at_price' => $v['compare_at_price'] ?? null,
                            'attributes' => $this->attributesFromInput($v),
                        ]);
                    } else {
                        $variant = $product->variants()->create([
                            'tenant_id' => $product->tenant_id,
                            'variant_name' => $v['variant_name'] ?: 'Default',
                            'purchase_price' => $v['purchase_price'] ?? 0,
                            'selling_price' => $v['selling_price'],
                            'compare_at_price' => $v['compare_at_price'] ?? null,
                            'attributes' => $this->attributesFromInput($v),
                        ]);

                        $warehouse ??= Warehouse::where('is_default', 1)->first() ?? Warehouse::first();
                        $qty = (int) ($v['stock'] ?? 0);

                        if ($warehouse) {
                            Inventory::create([
                                'tenant_id' => $tenant->id,
                                'variant_id' => $variant->id,
                                'warehouse_id' => $warehouse->id,
                                'quantity' => $qty,
                            ]);

                            if ($qty > 0) {
                                StockMovement::create([
                                    'tenant_id' => $tenant->id,
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
            // Offer/discount price — optional, and only meaningful as a
            // "was" price shown struck through when it's genuinely higher
            // than what the customer actually pays (selling_price). See
            // AiProductKnowledgeService/ProductVariant::discountPercent()
            // and the storefront card/detail views, which already read
            // this column — this was the missing write side.
            'variants.*.compare_at_price' => 'nullable|numeric|gt:variants.*.selling_price',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
            // Up to two option axes per variant (e.g. Size, Color) —
            // stored into the existing (previously write-never-used)
            // attributes JSON column. Deliberately capped at two: covers
            // this codebase's real product shapes (size, color, or both)
            // without building a fully dynamic N-axis option-type editor.
            'variants.*.attr1_name' => 'nullable|string|max:40',
            'variants.*.attr1_value' => 'nullable|string|max:60',
            'variants.*.attr2_name' => 'nullable|string|max:40',
            'variants.*.attr2_value' => 'nullable|string|max:60',
        ], [
            'variants.*.selling_price.required' => 'বিক্রয় মূল্য দিতে হবে।',
            'variants.*.compare_at_price.gt' => 'অফার/আগের মূল্য অবশ্যই বিক্রয় মূল্যের চেয়ে বেশি হতে হবে।',
        ]);
    }

    /**
     * Builds the attributes JSON payload from up to two optional name/value
     * pairs the form submits per variant row (attr1_name/attr1_value,
     * attr2_name/attr2_value) — null when neither pair was filled in, so a
     * plain single-price product's variant keeps attributes = null exactly
     * as before this feature existed, never an empty array on-disk.
     *
     * @return array<string, string>|null
     */
    protected function attributesFromInput(array $v): ?array
    {
        $attrs = [];

        foreach ([1, 2] as $n) {
            $name = trim((string) ($v["attr{$n}_name"] ?? ''));
            $value = trim((string) ($v["attr{$n}_value"] ?? ''));

            if ($name !== '' && $value !== '') {
                $attrs[$name] = $value;
            }
        }

        return $attrs ?: null;
    }
}
