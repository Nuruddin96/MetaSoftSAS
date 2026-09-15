<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use App\Services\LandingPage\SectionDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mobile Single Product Landing Page Builder — same LandingPage model,
 * SectionDataService, and section/status rules as the web panel's
 * Tenant\LandingPageController (Phase 2, already live). Sends the exact
 * same `data[...]`/`gallery_images[]` multipart field shape a web request
 * would, so SectionDataService::parse() needed zero changes to serve both.
 *
 * Every lookup takes a plain int + explicit tenant_id filter, never an
 * implicit `LandingPage $landingPage` binding — same reason
 * ProductCatalogController's docblock gives: SubstituteBindings runs before
 * this group's `bind.tenant.token` middleware, so `app('currentTenant')`
 * isn't bound yet when implicit binding would try to resolve it.
 *
 * Ordering/checkout itself is deliberately NOT part of this API — the
 * public landing page (web-rendered) remains the only place a customer
 * actually places an order, reusing Storefront\LandingPageController +
 * OrderPlacementService unchanged. This controller only manages content.
 */
class LandingPageController extends Controller
{
    public function __construct(protected SectionDataService $sectionData) {}

    public function index()
    {
        $pages = LandingPage::with('product')->latest()->get();

        return response()->json(['data' => $pages->map(fn (LandingPage $p) => $this->presentSummary($p))->all()]);
    }

    public function show(int $landingPage)
    {
        $landingPage = $this->find($landingPage, ['product.variants.inventory', 'product.images']);

        return response()->json($this->presentDetail($landingPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $product = Product::with('variants')
            ->where('tenant_id', app('currentTenant')->id)
            ->findOrFail($data['product_id']);

        $landingPage = LandingPage::create([
            'title' => $data['title'],
            'product_id' => $product->id,
            'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);

        return response()->json($this->presentDetail($landingPage->load('product.variants.inventory', 'product.images')), 201);
    }

    /** Title only — same as the web panel; the bound product is permanent, matching Tenant\LandingPageController::update(). */
    public function update(Request $request, int $landingPage)
    {
        $landingPage = $this->find($landingPage);

        $data = $request->validate(['title' => 'required|string|max:150']);
        $landingPage->update(['title' => $data['title']]);

        return response()->json($this->presentSummary($landingPage));
    }

    public function publish(int $landingPage)
    {
        $landingPage = $this->find($landingPage);
        $landingPage->update(['status' => 'published', 'published_at' => now()]);

        return response()->json($this->presentSummary($landingPage));
    }

    public function unpublish(int $landingPage)
    {
        $landingPage = $this->find($landingPage);
        $landingPage->update(['status' => 'draft']);

        return response()->json($this->presentSummary($landingPage));
    }

    public function destroy(int $landingPage)
    {
        $landingPage = $this->find($landingPage);

        foreach ($landingPage->sections ?? [] as $section) {
            foreach ($this->sectionData->imagePathsIn($section['data'] ?? []) as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $landingPage->delete();

        return response()->json(['ok' => true]);
    }

    /* ---------------- Sections ---------------- */

    public function addSection(Request $request, int $landingPage)
    {
        $landingPage = $this->find($landingPage);

        $data = $request->validate(['type' => 'required|string|in:'.implode(',', array_keys(LandingPage::sectionTypes()))]);

        $sections = $landingPage->sections ?? [];
        $section = ['id' => Str::random(10), 'type' => $data['type'], 'hidden' => false, 'data' => LandingPage::blankSectionData($data['type'])];
        $sections[] = $section;
        $landingPage->update(['sections' => $sections]);

        return response()->json($section, 201);
    }

    public function updateSection(Request $request, int $landingPage, string $sectionId)
    {
        [$landingPage, $sections, $index] = $this->findSection($landingPage, $sectionId);

        $section = $sections[$index];
        $section['data'] = $this->sectionData->parse($request, $section['type'], $section['data'] ?? []);
        $sections[$index] = $section;

        $landingPage->update(['sections' => $sections->values()->all()]);

        return response()->json($section);
    }

    public function destroySection(int $landingPage, string $sectionId)
    {
        [$landingPage, $sections] = $this->findSection($landingPage, $sectionId);
        $section = $sections->firstWhere('id', $sectionId);

        foreach ($this->sectionData->imagePathsIn($section['data'] ?? []) as $path) {
            Storage::disk('public')->delete($path);
        }

        $landingPage->update(['sections' => $sections->reject(fn ($s) => $s['id'] === $sectionId)->values()->all()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Full reorder in one call (send every section id in the new order) —
     * a mobile drag-reorder list naturally produces the final order at
     * once, unlike the web builder's repeated up/down taps, so this
     * mirrors ProductCatalogController::reorderImages()'s shape instead.
     */
    public function reorderSections(Request $request, int $landingPage)
    {
        $landingPage = $this->find($landingPage);

        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|string',
        ]);

        $sections = collect($landingPage->sections ?? []);
        $owned = $sections->pluck('id')->sort()->values();
        $requested = collect($data['order'])->sort()->values();

        if ($owned->all() !== $requested->all()) {
            return response()->json(['message' => 'অবৈধ সেকশন তালিকা।'], 422);
        }

        $bySections = $sections->keyBy('id');
        $reordered = collect($data['order'])->map(fn ($id) => $bySections[$id])->values()->all();

        $landingPage->update(['sections' => $reordered]);

        return response()->json(['data' => $reordered]);
    }

    public function toggleSection(int $landingPage, string $sectionId)
    {
        [$landingPage, $sections, $index] = $this->findSection($landingPage, $sectionId);

        $section = $sections[$index];
        $section['hidden'] = ! ($section['hidden'] ?? false);
        $sections[$index] = $section;

        $landingPage->update(['sections' => $sections->values()->all()]);

        return response()->json($section);
    }

    public function duplicateSection(int $landingPage, string $sectionId)
    {
        [$landingPage, $sections, $index] = $this->findSection($landingPage, $sectionId);

        $copy = $sections[$index];
        $copy['id'] = Str::random(10);
        $sections->splice($index + 1, 0, [$copy]);

        $landingPage->update(['sections' => $sections->values()->all()]);

        return response()->json($copy, 201);
    }

    /* ---------------- Helpers ---------------- */

    protected function find(int $id, array $with = []): LandingPage
    {
        return LandingPage::with($with)->where('tenant_id', app('currentTenant')->id)->findOrFail($id);
    }

    /** @return array{0: LandingPage, 1: Collection, 2?: int} */
    protected function findSection(int $id, string $sectionId): array
    {
        $landingPage = $this->find($id);
        $sections = collect($landingPage->sections ?? []);
        $index = $sections->search(fn ($s) => $s['id'] === $sectionId);
        abort_if($index === false, 404);

        return [$landingPage, $sections, $index];
    }

    protected function presentSummary(LandingPage $landingPage): array
    {
        return [
            'id' => $landingPage->id,
            'title' => $landingPage->title,
            'slug' => $landingPage->slug,
            'status' => $landingPage->status,
            'is_published' => $landingPage->isPublished(),
            'public_url' => app('currentTenant')->url().'/l/'.$landingPage->slug,
            'product' => $landingPage->relationLoaded('product') && $landingPage->product ? [
                'id' => $landingPage->product->id,
                'name' => $landingPage->product->name,
                'thumbnail_url' => $landingPage->product->thumbnail_path ? asset('storage/'.$landingPage->product->thumbnail_path) : null,
            ] : null,
            'created_at' => $landingPage->created_at?->toIso8601String(),
            'updated_at' => $landingPage->updated_at?->toIso8601String(),
        ];
    }

    protected function presentDetail(LandingPage $landingPage): array
    {
        // array_merge, not `+` — presentSummary() already has a slim
        // 'product' key, and `+` keeps the LEFT side on key collision, which
        // would silently discard this richer product+variants below.
        return array_merge($this->presentSummary($landingPage), [
            'section_types' => LandingPage::sectionTypes(),
            'sections' => collect($landingPage->sections ?? [])->map(fn ($s) => $this->presentSectionImages($s))->values()->all(),
            'product' => $landingPage->product ? [
                'id' => $landingPage->product->id,
                'name' => $landingPage->product->name,
                'description' => $landingPage->product->description,
                'thumbnail_url' => $landingPage->product->thumbnail_path ? asset('storage/'.$landingPage->product->thumbnail_path) : null,
                'has_variants' => $landingPage->product->variants->count() > 1,
                'variants' => $landingPage->product->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->variant_name,
                    'attributes' => $v->attributes,
                    'selling_price' => (float) $v->selling_price,
                    'compare_at_price' => $v->compare_at_price !== null ? (float) $v->compare_at_price : null,
                    'stock_quantity' => $v->relationLoaded('inventory') ? (int) $v->inventory->sum('quantity') : $v->totalStock(),
                ])->values()->all(),
            ] : null,
        ]);
    }

    /** Turns every stored path (`image_path`, `images[]`, `items[].photo_path`) into a full URL for the client — mirrors ReviewController::present()'s `photo_url` convention. */
    protected function presentSectionImages(array $section): array
    {
        $data = $section['data'] ?? [];

        if (! empty($data['image_path'])) {
            $data['image_url'] = asset('storage/'.$data['image_path']);
        }

        if (! empty($data['images'])) {
            $data['image_urls'] = collect($data['images'])->map(fn ($p) => asset('storage/'.$p))->values()->all();
        }

        if (! empty($data['items'])) {
            $data['items'] = collect($data['items'])->map(function ($item) {
                if (! empty($item['photo_path'])) {
                    $item['photo_url'] = asset('storage/'.$item['photo_path']);
                }

                return $item;
            })->values()->all();
        }

        $section['data'] = $data;

        return $section;
    }
}
