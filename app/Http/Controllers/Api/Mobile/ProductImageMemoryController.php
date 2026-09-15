<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TenantProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * "পণ্যের ছবি" (Product Image Memory) — mirrors
 * Tenant\ProductImageMemoryController's store/update/destroy exactly (same
 * TenantProductImage model, same tenant-scoped route binding, same
 * 'product-image-memory/{tenant_id}' storage prefix and ImageOptimizer
 * usage the web controller's own docblock describes). Web has no dedicated
 * list endpoint of its own — the Settings page pulls `productImageMemories`
 * inline (Tenant\SettingController::index()) — so index() here is
 * additive, not a mirror of an existing web route, same shape as
 * Api\Mobile\AiMemoryController::index() for its sibling text-memory
 * feature.
 *
 * Pure DB read/write plus a plain image upload only — never calls OpenAI,
 * same "don't burn tokens just to save a memory" constraint as the web
 * controller. Matching a saved image against a real customer message
 * happens later, at AI reply time, in App\Services\AI\
 * AiProductImageMemoryService — this controller never touches that.
 */
class ProductImageMemoryController extends Controller
{
    public function index()
    {
        if (! TenantProductImage::tablesReady()) {
            return response()->json(['data' => []]);
        }

        $images = TenantProductImage::orderByDesc('id')->get();

        return response()->json(['data' => $images->map(fn (TenantProductImage $i) => $this->present($i))->all()]);
    }

    public function store(Request $request, ImageOptimizer $optimizer)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        $image = TenantProductImage::create([
            'product_name' => $validated['product_name'],
            'image_path' => $this->storeImage($request, $optimizer),
        ]);

        return response()->json($this->present($image), 201);
    }

    public function update(Request $request, int $productImage, ImageOptimizer $optimizer)
    {
        $productImage = TenantProductImage::where('tenant_id', app('currentTenant')->id)->findOrFail($productImage);

        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        $fields = ['product_name' => $validated['product_name']];

        if ($request->hasFile('image')) {
            // Replacing the image must not leave the old file orphaned on
            // disk — same rule the web controller's update() follows.
            Storage::disk('public')->delete($productImage->image_path);
            $fields['image_path'] = $this->storeImage($request, $optimizer);
        }

        $productImage->update($fields);

        return response()->json($this->present($productImage->fresh()));
    }

    public function destroy(int $productImage)
    {
        $productImage = TenantProductImage::where('tenant_id', app('currentTenant')->id)->findOrFail($productImage);

        Storage::disk('public')->delete($productImage->image_path);
        $productImage->delete();

        return response()->json(['ok' => true]);
    }

    protected function storeImage(Request $request, ImageOptimizer $optimizer): string
    {
        return $optimizer->storeOptimized(
            $request->file('image'),
            'public',
            'product-image-memory/'.app('currentTenant')->id
        );
    }

    protected function present(TenantProductImage $i): array
    {
        return [
            'id' => $i->id,
            'product_name' => $i->product_name,
            'image_url' => asset('storage/'.$i->image_path),
            'created_at' => optional($i->created_at)->toIso8601String(),
        ];
    }
}
