<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * "পণ্যের ছবি" (Product Image Memory) — tenant-authored product-name ->
 * image mapping (database/sql/chunk50.sql, App\Models\TenantProductImage).
 * Pure DB read/write plus a plain image upload only — never calls OpenAI,
 * same "don't burn tokens just to save a memory" constraint as
 * Tenant\AiMemoryController, which this controller otherwise mirrors
 * closely (same tenant-isolation-via-route-binding, same edit/replace/
 * delete-without-orphaning shape). Matching a saved image against a real
 * customer message happens later, at AI reply time, in
 * App\Services\AI\AiProductImageMemoryService — this controller never
 * touches that.
 *
 * Images are resized/re-encoded via the same App\Services\ImageOptimizer
 * already used for product catalog thumbnails (Tenant\ProductController)
 * — same "products/{tenant_id}"-style directory convention, just a
 * distinct 'product-image-memory/{tenant_id}' prefix so these uploads
 * never collide with real catalog product images.
 */
class ProductImageMemoryController extends Controller
{
    public function store(Request $request, ImageOptimizer $optimizer)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        TenantProductImage::create([
            'product_name' => $validated['product_name'],
            'image_path' => $this->storeImage($request, $optimizer),
        ]);

        return back()->with('success', 'পণ্যের ছবি সেভ হয়েছে।');
    }

    public function update(Request $request, TenantProductImage $productImage, ImageOptimizer $optimizer)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        $fields = ['product_name' => $validated['product_name']];

        if ($request->hasFile('image')) {
            // Replacing the image must not leave the old file orphaned on
            // disk — same rule AiMemoryController::update() follows for a
            // replaced voice answer.
            Storage::disk('public')->delete($productImage->image_path);
            $fields['image_path'] = $this->storeImage($request, $optimizer);
        }

        $productImage->update($fields);

        return back()->with('success', 'পণ্যের ছবি আপডেট হয়েছে।');
    }

    public function destroy(TenantProductImage $productImage)
    {
        Storage::disk('public')->delete($productImage->image_path);
        $productImage->delete();

        return back()->with('success', 'পণ্যের ছবি মুছে ফেলা হয়েছে।');
    }

    protected function storeImage(Request $request, ImageOptimizer $optimizer): string
    {
        return $optimizer->storeOptimized(
            $request->file('image'),
            'public',
            'product-image-memory/'.app('currentTenant')->id
        );
    }
}
