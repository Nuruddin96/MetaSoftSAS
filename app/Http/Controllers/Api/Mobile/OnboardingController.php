<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\AI\AiProductDescriptionService;
use App\Services\ImageOptimizer;
use App\Services\Tenant\TenantOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mobile counterpart of Tenant\OnboardingController — same
 * TenantOnboardingService/AiProductDescriptionService underneath, JSON
 * responses instead of Blade views. Category add/remove during the
 * "categories" step deliberately has no endpoint here either, same as the
 * web wizard: the mobile app should call the EXISTING
 * GET/POST /mobile/v1/categories endpoints (Api\Mobile\CategoryController),
 * not a parallel one.
 *
 * No Flutter client exists in this workspace to consume this yet — added
 * so the backend doesn't need a second pass once it does (see
 * App\Http\Controllers\Api\Mobile\AuthController for the needs_onboarding/
 * onboarding_step fields surfaced at login/me time).
 */
class OnboardingController extends Controller
{
    public function __construct(protected TenantOnboardingService $onboarding) {}

    public function status(Request $request)
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'needs_onboarding' => $tenant->needsOnboarding(),
            'step' => $this->onboarding->currentStep($tenant),
            'steps' => TenantOnboardingService::STEPS,
            'business_types' => BusinessType::where('is_active', 1)->orderBy('sort_order')->get([
                'id', 'slug', 'name_bn', 'name_en', 'icon', 'default_attributes',
            ]),
        ]);
    }

    public function storeBusinessType(Request $request)
    {
        $data = $request->validate([
            'business_type_id' => 'required|exists:business_types,id',
            'business_type_other' => 'nullable|string|max:100',
        ]);

        $tenant = $request->user()->tenant;
        $this->onboarding->saveBusinessType($tenant, (int) $data['business_type_id'], $data['business_type_other'] ?? null);

        return response()->json(['step' => $this->onboarding->currentStep($tenant)]);
    }

    public function storeBusinessInfo(Request $request)
    {
        $data = $request->validate([
            'footer_address' => 'nullable|string|max:255',
            'business_division_id' => 'nullable|integer|exists:bd_divisions,id',
            'business_district_id' => 'nullable|integer|exists:bd_districts,id',
            'social_facebook' => 'nullable|url|max:255',
        ]);

        $tenant = $request->user()->tenant;
        $this->onboarding->saveBusinessInfo($tenant, $data);

        return response()->json(['step' => $this->onboarding->currentStep($tenant)]);
    }

    public function continueCategories(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->onboarding->advance($tenant, 'store_settings');

        return response()->json(['step' => $this->onboarding->currentStep($tenant)]);
    }

    public function storeStoreSettings(Request $request)
    {
        $data = $request->validate([
            'delivery_charge_inside_dhaka' => 'nullable|numeric|min:0',
            'delivery_charge_outside_dhaka' => 'nullable|numeric|min:0',
        ]);

        $tenant = $request->user()->tenant;
        $this->onboarding->saveStoreSettings($tenant, $data);

        return response()->json(['step' => $this->onboarding->currentStep($tenant)]);
    }

    public function storeFirstProduct(Request $request)
    {
        $tenant = $request->user()->tenant;

        if (! $tenant->isWithinLimit('max_products', Product::count())) {
            return response()->json(['message' => 'আপনার প্ল্যানের প্রোডাক্ট লিমিট শেষ। প্ল্যান আপগ্রেড করুন।'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:2000',
            'thumbnail' => 'nullable|image|max:4096',
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

        return response()->json(['product_id' => $product->id, 'step' => 'complete']);
    }

    public function skipFirstProduct(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->onboarding->advance($tenant, 'complete');

        return response()->json(['step' => 'complete']);
    }

    public function describeImage(Request $request)
    {
        $tenant = $request->user()->tenant;

        $data = $request->validate([
            'image' => 'required|image|max:4096',
            'product_name' => 'nullable|string|max:255',
        ]);

        $service = app(AiProductDescriptionService::class);

        if (! $service->available($tenant)) {
            return response()->json(['message' => 'এই মুহূর্তে AI ফিচারটি ব্যবহার করা যাচ্ছে না।'], 422);
        }

        $path = app(ImageOptimizer::class)
            ->storeOptimized($request->file('image'), 'public', 'products/'.$tenant->id);

        $result = $service->describe($tenant, asset('storage/'.$path), $data['product_name'] ?? null);

        if (! $result['success']) {
            Storage::disk('public')->delete($path);

            return response()->json(['message' => 'বর্ণনা তৈরি করা যায়নি। আবার চেষ্টা করুন।'], 422);
        }

        return response()->json([
            'description' => $result['description'],
            'image_path' => $path,
            'image_url' => asset('storage/'.$path),
        ]);
    }

    public function complete(Request $request)
    {
        $tenant = $request->user()->tenant;
        $this->onboarding->complete($tenant);

        return response()->json(['completed' => true]);
    }
}
