<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors Tenant\WebsiteController's review slice (storeReview/
 * updateReview/destroyReview) — a merchant-curated customer testimonial
 * (name + optional photo + review text), not a customer-submitted
 * moderation queue. Structurally identical to BannerController, since
 * the underlying Review model mirrors Banner exactly (see its docblock).
 */
class ReviewController extends Controller
{
    public function index()
    {
        if (! Review::tablesReady()) {
            return response()->json(['data' => []]);
        }

        $reviews = Review::orderBy('sort_order')->get();

        return response()->json(['data' => $reviews->map(fn (Review $r) => $this->present($r))->all()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'photo' => 'nullable|image|max:2048',
            'review_text' => 'nullable|string|max:500',
        ]);

        $tenant = app('currentTenant');
        $photoPath = $request->hasFile('photo')
            ? app(ImageOptimizer::class)->storeOptimized($request->file('photo'), 'public', 'reviews/'.$tenant->id)
            : null;

        $review = Review::create([
            'customer_name' => $data['customer_name'],
            'photo_path' => $photoPath,
            'review_text' => $data['review_text'] ?? null,
            'sort_order' => (int) Review::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return response()->json($this->present($review), 201);
    }

    public function update(Request $request, int $review)
    {
        $review = Review::where('tenant_id', app('currentTenant')->id)->findOrFail($review);

        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'photo' => 'nullable|image|max:2048',
            'review_text' => 'nullable|string|max:500',
        ]);

        $update = [
            'customer_name' => $data['customer_name'],
            'review_text' => $data['review_text'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            if ($review->photo_path) {
                Storage::disk('public')->delete($review->photo_path);
            }
            $update['photo_path'] = app(ImageOptimizer::class)
                ->storeOptimized($request->file('photo'), 'public', 'reviews/'.app('currentTenant')->id);
        }

        $review->update($update);

        return response()->json($this->present($review));
    }

    public function destroy(int $review)
    {
        $review = Review::where('tenant_id', app('currentTenant')->id)->findOrFail($review);

        if ($review->photo_path) {
            Storage::disk('public')->delete($review->photo_path);
        }
        $review->delete();

        return response()->json(['ok' => true]);
    }

    protected function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'customer_name' => $review->customer_name,
            'photo_url' => $review->photo_path ? asset('storage/'.$review->photo_path) : null,
            'review_text' => $review->review_text,
            'sort_order' => $review->sort_order,
            'is_active' => (bool) $review->is_active,
        ];
    }
}
