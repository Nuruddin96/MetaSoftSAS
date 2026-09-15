<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\AI\AiProductDescriptionService;
use App\Services\ImageOptimizer;
use App\Services\Tenant\TenantOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The guided post-signup wizard (Step 1-6, see TenantOnboardingService::STEPS)
 * shown once instead of dropping a new tenant into an empty dashboard.
 * Every step's "real" data (categories, the first product, store settings)
 * is saved through the SAME models/tables the rest of the panel uses —
 * this controller only ever adds the onboarding_step/business_type_id
 * bookkeeping on top, never a parallel draft store. Step 3 (categories)
 * deliberately has no store/destroy handler of its own here — it reuses
 * the existing tenant.categories.store/destroy routes directly from its
 * view, exactly like the real Categories page.
 */
class OnboardingController extends Controller
{
    public function __construct(protected TenantOnboardingService $onboarding) {}

    /** Entry point (tenant.onboarding.redirect) — sends the tenant to wherever they left off, or the dashboard if already done. */
    public function redirect()
    {
        $tenant = app('currentTenant');

        if ($this->onboarding->isComplete($tenant)) {
            return redirect()->route('tenant.dashboard');
        }

        return redirect()->route('tenant.onboarding.show', $this->onboarding->currentStep($tenant));
    }

    public function show(string $step)
    {
        $tenant = app('currentTenant');

        if ($this->onboarding->isComplete($tenant)) {
            return redirect()->route('tenant.dashboard');
        }

        abort_unless(in_array($step, TenantOnboardingService::STEPS, true), 404);

        return view("tenant.onboarding.{$step}", array_merge(
            ['tenant' => $tenant, 'step' => $step, 'steps' => TenantOnboardingService::STEPS],
            $this->stepData($tenant, $step)
        ));
    }

    protected function stepData(Tenant $tenant, string $step): array
    {
        return match ($step) {
            'business_type' => [
                'businessTypes' => BusinessType::where('is_active', 1)->orderBy('sort_order')->get(),
            ],
            'business_info' => [
                'divisions' => DB::table('bd_divisions')->orderBy('name')->get(),
                'districts' => DB::table('bd_districts')->orderBy('name')->get(),
            ],
            'categories' => [
                'categories' => Category::withCount('products')->orderBy('name')->get(),
                'businessType' => $tenant->businessType,
            ],
            'store_settings' => [
                'store' => StoreSetting::pluck('value', 'key'),
            ],
            'first_product' => [
                'categories' => Category::orderBy('name')->get(),
                'attributeSuggestions' => $tenant->businessType?->default_attributes ?? [],
            ],
            'complete' => [
                'productCount' => Product::count(),
                'categoryCount' => Category::count(),
            ],
            default => [],
        };
    }

    public function storeBusinessType(Request $request)
    {
        $data = $request->validate([
            'business_type_id' => 'required|exists:business_types,id',
            'business_type_other' => 'nullable|string|max:100',
        ]);

        $this->onboarding->saveBusinessType(
            app('currentTenant'),
            (int) $data['business_type_id'],
            $data['business_type_other'] ?? null,
        );

        return redirect()->route('tenant.onboarding.show', 'business_info');
    }

    public function storeBusinessInfo(Request $request)
    {
        $data = $request->validate([
            'footer_address' => 'nullable|string|max:255',
            'business_division_id' => 'nullable|integer|exists:bd_divisions,id',
            'business_district_id' => 'nullable|integer|exists:bd_districts,id',
            'social_facebook' => 'nullable|url|max:255',
        ]);

        $this->onboarding->saveBusinessInfo(app('currentTenant'), $data);

        return redirect()->route('tenant.onboarding.show', 'categories');
    }

    /** No fields of its own — categories are added/removed via the existing tenant.categories.store/destroy routes from this step's view. */
    public function continueCategories(Request $request)
    {
        $this->onboarding->advance(app('currentTenant'), 'store_settings');

        return redirect()->route('tenant.onboarding.show', 'store_settings');
    }

    public function storeStoreSettings(Request $request)
    {
        $data = $request->validate([
            'delivery_charge_inside_dhaka' => 'nullable|numeric|min:0',
            'delivery_charge_outside_dhaka' => 'nullable|numeric|min:0',
        ]);

        $this->onboarding->saveStoreSettings(app('currentTenant'), $data);

        return redirect()->route('tenant.onboarding.show', 'first_product');
    }

    public function storeFirstProduct(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $tenant->isWithinLimit('max_products', Product::count())) {
            return back()->withInput()->with('error', 'আপনার প্ল্যানের প্রোডাক্ট লিমিট শেষ। প্ল্যান আপগ্রেড করুন।');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:2000',
            'thumbnail' => 'nullable|image|max:4096',
            // Set by describeImage() when the tenant used the AI button —
            // the image is already stored on disk at that point, so the
            // final submit here must reference it rather than re-uploading.
            'thumbnail_path' => 'nullable|string|max:255',
        ]);

        $thumbnailPath = $data['thumbnail_path'] ?? null;

        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = app(ImageOptimizer::class)
                ->storeOptimized($request->file('thumbnail'), 'public', 'products/'.$tenant->id);
        }

        $product = Product::create([
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'] ?? null,
            'thumbnail_path' => $thumbnailPath,
            'is_active' => 1,
        ]);

        $variant = $product->variants()->create([
            'tenant_id' => $tenant->id,
            'variant_name' => 'Default',
            'purchase_price' => 0,
            'selling_price' => $data['selling_price'],
        ]);

        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();
        if ($warehouse) {
            Inventory::create([
                'tenant_id' => $tenant->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 0,
            ]);
        }

        $this->onboarding->advance($tenant, 'complete');

        return redirect()->route('tenant.onboarding.show', 'complete');
    }

    public function skipFirstProduct(Request $request)
    {
        $this->onboarding->advance(app('currentTenant'), 'complete');

        return redirect()->route('tenant.onboarding.show', 'complete');
    }

    /**
     * AJAX: "ছবি দেখে বর্ণনা লিখে দাও". Stores the image immediately (it
     * becomes the product's thumbnail on real submit, referenced back via
     * thumbnail_path — see storeFirstProduct()) so it has a public URL to
     * hand the vision model, then calls the AI. Never runs automatically —
     * only when the tenant clicks the button.
     */
    public function describeImage(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'image' => 'required|image|max:4096',
            'product_name' => 'nullable|string|max:255',
        ]);

        $service = app(AiProductDescriptionService::class);

        if (! $service->available($tenant)) {
            return response()->json([
                'success' => false,
                'message' => 'এই মুহূর্তে AI ফিচারটি ব্যবহার করা যাচ্ছে না।',
            ], 422);
        }

        $path = app(ImageOptimizer::class)
            ->storeOptimized($request->file('image'), 'public', 'products/'.$tenant->id);

        $result = $service->describe($tenant, asset('storage/'.$path), $data['product_name'] ?? null);

        if (! $result['success']) {
            Storage::disk('public')->delete($path);

            return response()->json([
                'success' => false,
                'message' => 'বর্ণনা তৈরি করা যায়নি। আবার চেষ্টা করুন।',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'description' => $result['description'],
            'image_path' => $path,
            'image_url' => asset('storage/'.$path),
        ]);
    }

    public function complete(Request $request)
    {
        $tenant = app('currentTenant');

        $this->onboarding->complete($tenant);

        return redirect()->route('tenant.dashboard')->with('success', 'আপনার স্টোর প্রস্তুত! 🎉');
    }
}
