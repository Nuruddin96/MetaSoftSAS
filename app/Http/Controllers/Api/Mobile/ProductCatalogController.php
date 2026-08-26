<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The real Products feature (list/detail/create/edit) for the mobile app —
 * distinct from ProductController::index() in this same namespace, which is
 * a narrow variant-search slice built only for Create Order's product
 * picker and must keep its existing shape (Orders depends on it; not
 * touched here).
 *
 * Variant/Attribute Generator project: the mobile form now mirrors the web
 * panel's attribute-driven variant workflow (see `variants[]`'s
 * `attributes`/`compare_at_price` support in store()/storeVariant()/
 * updateVariant() below) — the "pared down, no per-variant editing" note
 * that used to be here is no longer accurate; kept only the genuinely
 * still-true limitation: whole-product update() only touches a single
 * variant's price/SKU when the product has exactly one, see that guard.
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
        $this->assertNoDuplicateVariantCombinations($data['variants'] ?? []);
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
                    'description' => $data['description'] ?? null,
                    'is_active' => true,
                    'has_variants' => count($rows) > 1 ? 1 : 0,
                ]);

                if ($request->hasFile('thumbnail')) {
                    $path = app(ImageOptimizer::class)->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$tenant->id);
                    $uploadedPaths[] = $path;
                    $product->update(['thumbnail_path' => $path]);
                }

                $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

                foreach ($rows as $row) {
                    $variant = $product->variants()->create([
                        'tenant_id' => $tenant->id,
                        'variant_name' => ($row['name'] ?? null) ?: 'Default',
                        'selling_price' => $row['selling_price'] ?? $data['selling_price'],
                        'sku' => count($rows) === 1 ? ($row['sku'] ?? $data['sku'] ?? null) : ($row['sku'] ?? null),
                        'purchase_price' => $row['purchase_price'] ?? 0,
                        'compare_at_price' => $row['compare_at_price'] ?? null,
                        'attributes' => $this->attributesFromRow($row),
                    ]);

                    // Per-row stock (falls back to the single top-level
                    // initial_stock for the simple/no-attributes path,
                    // unchanged from before).
                    $qty = (int) ($row['stock'] ?? $data['initial_stock'] ?? 0);

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
                    'description' => array_key_exists('description', $data) ? $data['description'] : $product->description,
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
            // Web/Flutter parity project — this was read-only on mobile
            // before (presentDetail() returned it, but no create/update
            // path ever wrote it).
            'description' => 'nullable|string|max:2000',
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
            'variants.*.compare_at_price' => 'nullable|numeric|gt:variants.*.selling_price',
            // Web/Flutter parity project — the web form's "কেনা দাম" (cost)
            // field, used for profit reports (Order::profit()). Never
            // shown to storefront customers, only to the tenant editing.
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
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
            'description' => 'nullable|string|max:2000',
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
                'purchase_price' => (float) $v->purchase_price,
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
     * Attribute/Variant Generator project — rejects a submission where two
     * rows resolve to the identical attribute combination, regardless of
     * insertion order within each row's map. Rows with no attributes at
     * all are never compared against each other.
     */
    protected function assertNoDuplicateVariantCombinations(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $attrs = $this->attributesFromRow($row);
            if ($attrs === null) {
                continue;
            }
            ksort($attrs);
            $key = json_encode($attrs);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'variants' => ['একই অ্যাট্রিবিউট কম্বিনেশনের একাধিক ভ্যারিয়েন্ট থাকতে পারবে না।'],
                ]);
            }
            $seen[$key] = true;
        }
    }

    /**
     * New, additive — add one variant to an existing product without
     * resubmitting the whole product form. Mirrors store()'s per-row
     * shape exactly (name/selling_price/sku/attributes).
     */
    public function storeVariant(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->with('variants')->findOrFail($product);

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'selling_price' => 'required|numeric|min:0',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)],
            'compare_at_price' => 'nullable|numeric|gt:selling_price',
            'purchase_price' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:60',
        ]);

        $newAttrs = $this->attributesFromRow($data);
        if ($newAttrs !== null) {
            ksort($newAttrs);
            foreach ($product->variants as $existing) {
                $existingAttrs = $existing->attributes;
                if (is_array($existingAttrs)) {
                    ksort($existingAttrs);
                }
                if ($existingAttrs === $newAttrs) {
                    throw ValidationException::withMessages([
                        'attributes' => ['এই অ্যাট্রিবিউট কম্বিনেশনের ভ্যারিয়েন্ট আগে থেকেই আছে।'],
                    ]);
                }
            }
        }

        $variant = $product->variants()->create([
            'tenant_id' => $tenant->id,
            'variant_name' => $data['name'] ?: 'Default',
            'selling_price' => $data['selling_price'],
            'sku' => $data['sku'] ?? null,
            'compare_at_price' => $data['compare_at_price'] ?? null,
            'purchase_price' => $data['purchase_price'] ?? 0,
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
        $product = Product::where('tenant_id', $tenant->id)->with('variants')->findOrFail($product);
        $variant = $product->variants()->findOrFail($variant);

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'selling_price' => 'nullable|numeric|min:0',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('product_variants', 'sku')->where('tenant_id', $tenant->id)->ignore($variant->id)],
            // Compares against whichever selling_price is actually in
            // effect after this update — the just-submitted one if given,
            // else the variant's existing one. A plain `gt:selling_price`
            // rule only compares against a field in THIS same request, so
            // it wrongly failed whenever compare_at_price was updated on
            // its own without also resubmitting selling_price.
            'compare_at_price' => ['nullable', 'numeric', function ($attribute, $value, $fail) use ($request, $variant) {
                $effectiveSellingPrice = $request->input('selling_price', $variant->selling_price);
                if ($value <= $effectiveSellingPrice) {
                    $fail('আগের দাম অবশ্যই বিক্রয় মূল্যের চেয়ে বেশি হতে হবে।');
                }
            }],
            'purchase_price' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:60',
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('attributes', $data)) {
            $newAttrs = $this->attributesFromRow($data);
            if ($newAttrs !== null) {
                ksort($newAttrs);
                foreach ($product->variants as $existing) {
                    if ($existing->id === $variant->id) {
                        continue;
                    }
                    $existingAttrs = $existing->attributes;
                    if (is_array($existingAttrs)) {
                        ksort($existingAttrs);
                    }
                    if ($existingAttrs === $newAttrs) {
                        throw ValidationException::withMessages([
                            'attributes' => ['এই অ্যাট্রিবিউট কম্বিনেশনের ভ্যারিয়েন্ট আগে থেকেই আছে।'],
                        ]);
                    }
                }
            }
        }

        $variant->update([
            'variant_name' => $data['name'] ?? $variant->variant_name,
            'selling_price' => $data['selling_price'] ?? $variant->selling_price,
            'sku' => $data['sku'] ?? $variant->sku,
            'compare_at_price' => array_key_exists('compare_at_price', $data) ? $data['compare_at_price'] : $variant->compare_at_price,
            'purchase_price' => array_key_exists('purchase_price', $data) ? $data['purchase_price'] : $variant->purchase_price,
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

    /**
     * Web/Flutter parity project — gallery images (distinct from the single
     * `thumbnail`) were readable via presentDetail()'s `images` array but
     * had no mobile write path at all before this. Mirrors the web panel's
     * `gallery[]` upload (`Tenant\ProductController::store()`/`update()`):
     * same 8-image cap, same `image|max:4096` validation, same storage
     * disk/optimizer.
     */
    public function storeImages(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->with('images')->findOrFail($product);

        $data = $request->validate([
            'images' => 'required|array|min:1',
            'images.*' => 'image|max:4096',
        ]);

        if ($product->images->count() + count($data['images']) > 8) {
            return response()->json(['message' => 'সর্বোচ্চ ৮টি গ্যালারি ছবি রাখা যাবে।'], 422);
        }

        $nextSort = ((int) $product->images->max('sort_order')) + ($product->images->isEmpty() ? 0 : 1);
        $uploadedPaths = [];

        try {
            foreach ($data['images'] as $file) {
                $path = app(ImageOptimizer::class)->storeOptimized($file, 'public', 'products/gallery');
                $uploadedPaths[] = $path;
                $product->images()->create(['image_path' => $path, 'sort_order' => $nextSort++]);
            }
        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            report($e);

            return response()->json(['message' => 'ছবি আপলোড করা যায়নি। আবার চেষ্টা করুন।'], 500);
        }

        $product->load('images');

        return response()->json([
            'images' => $product->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => asset('storage/'.$img->image_path),
                'sort_order' => $img->sort_order,
            ])->values()->all(),
        ], 201);
    }

    /** Web/Flutter parity project — mirrors `Tenant\ProductController::destroyImage()`. Featured thumbnail is untouched by this. */
    public function destroyImage(int $product, int $image)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->findOrFail($product);
        $img = $product->images()->where('id', $image)->firstOrFail();

        Storage::disk('public')->delete($img->image_path);
        $img->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Web/Flutter parity project — mirrors `Tenant\ProductController::
     * reorderImages()`: persist drag-reordered gallery order. Every
     * submitted id must belong to this product (same ownership guard as
     * the web panel's version).
     */
    public function reorderImages(Request $request, int $product)
    {
        $tenant = app('currentTenant');
        $product = Product::where('tenant_id', $tenant->id)->with('images')->findOrFail($product);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|integer',
        ]);

        $ids = $data['order'];
        $owned = $product->images->pluck('id');

        if ($owned->count() !== count($ids) || $owned->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
            return response()->json(['message' => 'অবৈধ ছবি তালিকা।'], 422);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ProductImage::where('id', $id)->update(['sort_order' => $position]);
            }
        });

        $product->load('images');

        return response()->json([
            'images' => $product->images->sortBy('sort_order')->map(fn ($img) => [
                'id' => $img->id,
                'url' => asset('storage/'.$img->image_path),
                'sort_order' => $img->sort_order,
            ])->values()->all(),
        ]);
    }
}
