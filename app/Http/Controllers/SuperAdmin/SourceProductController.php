<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SourceProduct;
use App\Models\SourceProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SourceProductController extends Controller
{
    public function index()
    {
        return view('super.source.products', [
            'products' => SourceProduct::with('images')->orderBy('sort_order')->paginate(20),
        ]);
    }

    public function create()
    {
        return view('super.source.product-form', ['product' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $images = $data['images'] ?? [];
        unset($data['images']);

        $product = SourceProduct::create($data);

        $this->storeImages($product, $images);

        return redirect()->route('super.source.products')->with('success', 'প্রোডাক্ট যোগ হয়েছে।');
    }

    public function edit(SourceProduct $product)
    {
        $product->load('images');

        return view('super.source.product-form', compact('product'));
    }

    public function update(Request $request, SourceProduct $product)
    {
        $data = $this->validated($request);

        $images = $data['images'] ?? [];
        unset($data['images']);

        $product->update($data);

        $this->storeImages($product, $images);

        return redirect()->route('super.source.products')->with('success', 'প্রোডাক্ট আপডেট হয়েছে।');
    }

    public function destroyImage(SourceProductImage $image)
    {
        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return back()->with('success', 'ছবি মুছে ফেলা হয়েছে।');
    }

    public function destroy(SourceProduct $product)
    {
        foreach ($product->images as $img) {
            Storage::disk('public')->delete($img->image_path);
        }
        $product->delete();

        return back()->with('success', 'প্রোডাক্ট মুছে ফেলা হয়েছে।');
    }

    protected function storeImages(SourceProduct $product, array $images): void
    {
        $maxOrder = (int) $product->images()->max('sort_order');

        foreach ($images as $image) {
            $maxOrder++;
            SourceProductImage::create([
                'source_product_id' => $product->id,
                'image_path' => $image->store('source-products/'.$product->id, 'public'),
                'sort_order' => $maxOrder,
            ]);
        }

        // keep legacy single-image column in sync with the first uploaded image (for old references)
        if (! $product->image_path && $product->images()->exists()) {
            $product->update(['image_path' => $product->images()->orderBy('sort_order')->first()->image_path]);
        }
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'unit_price' => 'required|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0|gte:unit_price',
            'min_order_qty' => 'nullable|integer|min:1',
            'delivery_time_days' => 'nullable|string|max:50',
            'shipping_cost' => 'nullable|numeric|min:0',
            'images' => 'nullable|array|max:8',
            'images.*' => 'image|max:4096',
        ], [
            'max_price.gte' => 'সর্বোচ্চ দাম সর্বনিম্ন দামের চেয়ে বেশি হতে হবে।',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
