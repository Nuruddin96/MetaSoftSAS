<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The real Products feature (list/detail/create/edit) for the mobile app —
 * distinct from ProductController::index() in this same namespace, which is
 * a narrow variant-search slice built only for Create Order's product
 * picker and must keep its existing shape (Orders depends on it; not
 * touched here).
 *
 * Field set is deliberately pared down to what the mobile app's own form
 * actually captures today (lib/features/products/data/models/product_form_data.dart):
 * one selling_price + optional variant NAMES only — no per-variant
 * price/purchase_price/compare_at_price/attribute-axis editing. Those stay
 * web-panel-only (Tenant\ProductController) until the mobile form grows to
 * support them; see update()'s single-variant guard below.
 *
 * update() is POST, not PATCH/PUT: PHP does not populate $_FILES for
 * multipart bodies on any method other than POST, so a real multipart
 * PATCH from a mobile HTTP client would silently lose the uploaded file.
 * This is the same reason HTML forms spoof PUT/PATCH via a hidden
 * `_method` field — here the route itself is just POST.
 *
 * show()/update() take a plain int route parameter and look the product up
 * explicitly filtered by tenant_id, never an implicit `Product $product`
 * binding — see show()'s docblock for why implicit binding 404s even for
 * the owning tenant on this middleware stack. Same convention
 * OrderController/CustomerController already use.
 */
class ProductCatalogController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with(['category.parent', 'variants.inventory'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->toString();
                $query->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%{$q}%")
                        ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', (int) $request->input('category_id')))
            ->when($request->boolean('low_stock'), fn ($query) => $query->whereHas('variants', function ($v) {
                $v->where('is_active', 1)
                    ->whereRaw('(select coalesce(sum(quantity), 0) from inventory where inventory.variant_id = product_variants.id) <= product_variants.low_stock_threshold');
            }))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $p) => $this->presentSummary($p))->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Takes a plain int, not an implicit `Product $product` binding: Laravel
     * resolves implicit route-model bindings via SubstituteBindings, which
     * runs before this group's own `bind.tenant.token` route middleware —
     * `app()->bound('currentTenant')` is still false at that point, so
     * Product::resolveRouteBinding() would fall back to URL-based tenant
     * resolution (no tenant on a central API host) and 404 even for the
     * owning tenant. Same explicit-lookup convention OrderController/
     * CustomerController already use for this exact reason — see this
     * class's own docblock.
     */
    public function show(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)
            ->with(['category.parent', 'variants.inventory.warehouse', 'images' => fn ($q) => $q->orderBy('sort_order')])
            ->findOrFail($product);

        return response()->json($this->presentDetail($product));
    }

    public function store(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $tenant->isWithinLimit('max_products', Product::count())) {
            return response()->json(['message' => 'আপনার প্ল্যানের প্রোডাক্ট লিমিট শেষ। প্ল্যান আপগ্রেড করুন।'], 422);
        }

        $data = $this->validateCreate($request);
        $uploadedPaths = [];

        try {
            $product = DB::transaction(function () use ($request, $data, $tenant, &$uploadedPaths) {
                // New, additive path: a richer `variants[]` array (each row
                // with its own name/selling_price/sku/attributes — built by
                // the Attributes/variant-combination UI) wins when present.
                // Otherwise falls back to the original flat `variant_names`
                // + single top-level `selling_price`, unchanged, for the
                // simple-product form that has always worked this way.
                $rows = ! empty($data['variants'])
                    ? $data['variants']
                    : array_map(
                        fn ($name) => ['name' => $name, 'selling_price' => $data['selling_price'], 'sku' => null],
                        ! empty($data['variant_names']) ? $data['variant_names'] : [null],
                    );

                $product = Product::create([
                    'name' => $data['name'],
                    'category_id' => $data['category_id'] ?? null,
                    'is_active' => true,
                    'has_variants' => count($rows) > 1 ? 1 : 0,
                ]);

                if ($request->hasFile('thumbnail')) {
                    $path = app(ImageOptimizer::class)->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$tenant->id);
                    $uploadedPaths[] = $path;
                    $product->update(['thumbnail_path' => $path]);
                }

                $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();
                $qty = (int) ($data['initial_stock'] ?? 0);

                foreach ($rows as $row) {
                    $variant = $product->variants()->create([
                        'tenant_id' => $tenant->id,
                        'variant_name' => ($row['name'] ?? null) ?: 'Default',
                        'selling_price' => $row['selling_price'] ?? $data['selling_price'],
                        'sku' => count($rows) === 1 ? ($row['sku'] ?? $data['sku'] ?? null) : ($row['sku'] ?? null),
                        'attributes' => $this->attributesFromRow($row),
                    ]);

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
                                'user_id' => auth()->id(),
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

            return response()->json(['message' => 'প্রোডাক্ট তৈরি করা যায়নি — কোনো পরিবর্তন সেভ হয়নি। আবার চেষ্টা করুন।'], 500);
        }

        $product->load(['category', 'variants.inventory.warehouse', 'images']);

        return response()->json($this->presentDetail($product), 201);
    }

    public function update(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->with('variants')->findOrFail($product);
        $data = $this->validateUpdate($request, $product);
        $uploadedPaths = [];

        try {
            DB::transaction(function () use ($request, $data, $product, $tenant, &$uploadedPaths) {
                $product->update([
                    'name' => $data['name'],
                    'category_id' => $data['category_id'] ?? null,
                    'is_active' => $data['is_active'] ?? $product->is_active,
                ]);

                if ($request->hasFile('thumbnail')) {
                    $path = app(ImageOptimizer::class)->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$tenant->id);
                    $uploadedPaths[] = $path;
                    $product->update(['thumbnail_path' => $path]);
                }

                // Price/SKU are only safely single-valued when the product has
                // exactly one variant — the mobile edit form has one price
                // field, no per-variant editor. A multi-variant product's
                // per-variant prices are left untouched rather than guessed.
                if (array_key_exists('selling_price', $data) && $product->variants->count() === 1) {
                    $variant = $product->variants->first();
                    $variant->update([
                        'selling_price' => $data['selling_price'],
                        'sku' => $data['sku'] ?? $variant->sku,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            report($e);

            return response()->json(['message' => 'প্রোডাক্ট আপডেট করা যায়নি — কোনো পরিবর্তন সেভ হয়নি। আবার চেষ্টা করুন।'], 500);
        }

        $product->load(['category', 'variants.inventory.warehouse', 'images']);

        return response()->json($this->presentDetail($product));
    }

    protected function validateCreate(Request $request): array
    {
        $tenant = app('currentTenant');

        return $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant->id)],
            // Only required when the caller isn't using the new per-row
            // `variants[]` path below (each row supplies its own price).
            'selling_price' => 'required_without:variants|nullable|numeric|min:0.01',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)],
            'initial_stock' => 'nullable|integer|min:0',
            'variant_names' => 'nullable|array|max:20',
            'variant_names.*' => 'string|max:150',
            // New, additive alternative to variant_names — wins when
            // present, see store()'s doc comment.
            'variants' => 'nullable|array|max:50',
            'variants.*.name' => 'nullable|string|max:150',
            'variants.*.selling_price' => 'required_with:variants|numeric|min:0.01',
            'variants.*.sku' => 'nullable|string|max:80',
            'variants.*.attributes' => 'nullable|array',
            'variants.*.attributes.*' => 'nullable|string|max:60',
            'thumbnail' => 'nullable|image|max:4096',
        ], [
            'category_id.exists' => 'অবৈধ ক্যাটাগরি।',
            'sku.unique' => 'এই SKU ইতিমধ্যে ব্যবহৃত হয়েছে।',
        ]);
    }

    protected function validateUpdate(Request $request, Product $product): array
    {
        $tenant = app('currentTenant');
        $currentVariantId = $product->variants->count() === 1 ? $product->variants->first()->id : null;

        return $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant->id)],
            'selling_price' => 'nullable|numeric|min:0.01',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)->ignore($currentVariantId)],
            'is_active' => 'nullable|boolean',
            'thumbnail' => 'nullable|image|max:4096',
        ], [
            'category_id.exists' => 'অবৈধ ক্যাটাগরি।',
            'sku.unique' => 'এই SKU ইতিমধ্যে ব্যবহৃত হয়েছে।',
        ]);
    }

    protected function presentSummary(Product $product): array
    {
        $variants = $product->variants;
        $stock = $variants->sum(fn (ProductVariant $v) => $v->relationLoaded('inventory') ? $v->inventory->sum('quantity') : $v->totalStock());
        $prices = $variants->pluck('selling_price')->filter(fn ($p) => $p !== null);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $variants->count() === 1 ? $variants->first()->sku : null,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            // Catalog Architecture project — null when the product's
            // category is itself top-level (or the product has none); set
            // to the parent's name when it's a subcategory, so the client
            // can show "Category: X / Subcategory: Y" instead of just Y.
            'parent_category_name' => $product->category?->parent?->name,
            'selling_price' => $prices->isNotEmpty() ? (float) $prices->min() : 0,
            'stock_quantity' => (int) $stock,
            'is_active' => (bool) $product->is_active,
            'has_variants' => $variants->count() > 1,
            'thumbnail_url' => $product->thumbnail_path ? asset('storage/'.$product->thumbnail_path) : null,
        ];
    }

    protected function presentDetail(Product $product): array
    {
        $warehouseTotals = [];
        foreach ($product->variants as $variant) {
            foreach ($variant->inventory as $row) {
                $name = $row->warehouse?->name ?? 'Unknown';
                $warehouseTotals[$name] = ($warehouseTotals[$name] ?? 0) + (int) $row->quantity;
            }
        }

        return $this->presentSummary($product) + [
            'description' => $product->description,
            'variants' => $product->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->variant_name,
                'sku' => $v->sku,
                'selling_price' => (float) $v->selling_price,
                'compare_at_price' => $v->compare_at_price !== null ? (float) $v->compare_at_price : null,
                'stock_quantity' => $v->relationLoaded('inventory') ? (int) $v->inventory->sum('quantity') : $v->totalStock(),
                'is_active' => (bool) $v->is_active,
                // New, additive — was never returned before (only ever
                // read via the web ProductController for storefront
                // display). Whatever shape an existing variant's JSON
                // happens to have (including `null`) passes through as-is;
                // no coercion, so an older row never becomes {} on read.
                'attributes' => $v->attributes,
            ])->values()->all(),
            'warehouse_stock' => collect($warehouseTotals)->map(fn ($qty, $name) => [
                'warehouse_name' => $name,
                'quantity' => $qty,
            ])->values()->all(),
            'images' => $product->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => asset('storage/'.$img->image_path),
                'sort_order' => $img->sort_order,
            ])->values()->all(),
        ];
    }

    /** @return array<string, string>|null */
    protected function attributesFromRow(array $row): ?array
    {
        if (empty($row['attributes']) || ! is_array($row['attributes'])) {
            return null;
        }

        $attrs = [];
        foreach ($row['attributes'] as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $attrs[$name] = $value;
            }
        }

        return $attrs ?: null;
    }

    /**
     * New, additive — add one variant to an existing product without
     * resubmitting the whole product form. Mirrors store()'s per-row
     * shape exactly (name/selling_price/sku/attributes).
     */
    public function storeVariant(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->findOrFail($product);

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'selling_price' => 'required|numeric|min:0',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)],
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:60',
        ]);

        $variant = $product->variants()->create([
            'tenant_id' => $tenant->id,
            'variant_name' => $data['name'] ?: 'Default',
            'selling_price' => $data['selling_price'],
            'sku' => $data['sku'] ?? null,
            'attributes' => $this->attributesFromRow($data),
        ]);

        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();
        if ($warehouse) {
            Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0]);
        }

        if ($product->variants()->count() > 1) {
            $product->update(['has_variants' => true]);
        }

        return response()->json(['id' => $variant->id], 201);
    }

    /** New, additive — edit one variant's own fields directly. */
    public function updateVariant(Request $request, int $product, int $variant)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->findOrFail($product);
        $variant = $product->variants()->findOrFail($variant);

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'selling_price' => 'nullable|numeric|min:0',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)->ignore($variant->id)],
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:60',
            'is_active' => 'nullable|boolean',
        ]);

        $variant->update([
            'variant_name' => $data['name'] ?? $variant->variant_name,
            'selling_price' => $data['selling_price'] ?? $variant->selling_price,
            'sku' => $data['sku'] ?? $variant->sku,
            'attributes' => array_key_exists('attributes', $data) ? $this->attributesFromRow($data) : $variant->attributes,
            'is_active' => $data['is_active'] ?? $variant->is_active,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * New, additive — delete one variant. Blocked when it's the product's
     * only remaining variant: `product_variants`' own schema comment
     * states "every product has at least ONE variant row", and Order/POS/
     * storefront code all assume a product resolves to at least one
     * sellable row.
     */
    public function destroyVariant(int $product, int $variant)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->findOrFail($product);
        $variant = $product->variants()->findOrFail($variant);

        if ($product->variants()->count() <= 1) {
            return response()->json(['message' => 'প্রোডাক্টের অন্তত একটি ভ্যারিয়েন্ট থাকতে হবে।'], 422);
        }

        $variant->delete();

        if ($product->variants()->count() <= 1) {
            $product->update(['has_variants' => false]);
        }

        return response()->json(['ok' => true]);
    }

    /** Mirrors Tenant\ProductController::destroy() — whole-product delete, missing from this mobile surface until now. */
    public function destroy(int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->findOrFail($product);
        $product->delete();

        return response()->json(['ok' => true]);
    }
}
