<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Single Product Landing Page Builder (Phase 2). Each section is its own
 * independent mini-form with its own save/delete/move action — deliberately
 * not one giant multi-section form — so a non-technical tenant edits one
 * thing at a time with a plain server round trip, no JS framework needed
 * (matches every other tenant panel page in this app).
 */
class LandingPageController extends Controller
{
    public function index()
    {
        return view('tenant.landing-pages.index', [
            'tenant' => app('currentTenant'),
            'pages' => LandingPage::with('product')->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.landing-pages.create', [
            'products' => Product::where('is_active', 1)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $product = Product::with('variants')->findOrFail($data['product_id']);

        $landingPage = LandingPage::create([
            'title' => $data['title'],
            'product_id' => $product->id,
            'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ]);

        return redirect()->route('tenant.landing-pages.edit', $landingPage)->with('success', 'ল্যান্ডিং পেজ তৈরি হয়েছে। এখন সেকশনগুলো সাজান।');
    }

    public function edit(LandingPage $landingPage)
    {
        return view('tenant.landing-pages.edit', [
            'tenant' => app('currentTenant'),
            'landingPage' => $landingPage,
            'sectionTypes' => LandingPage::sectionTypes(),
        ]);
    }

    public function update(Request $request, LandingPage $landingPage)
    {
        $data = $request->validate(['title' => 'required|string|max:150']);
        $landingPage->update(['title' => $data['title']]);

        return back()->with('success', 'শিরোনাম আপডেট হয়েছে।');
    }

    public function publish(LandingPage $landingPage)
    {
        $landingPage->update(['status' => 'published', 'published_at' => now()]);

        return back()->with('success', 'পেজটি পাবলিশ হয়েছে — এখন সবাই দেখতে পাবে।');
    }

    public function unpublish(LandingPage $landingPage)
    {
        $landingPage->update(['status' => 'draft']);

        return back()->with('success', 'পেজটি আনপাবলিশ করা হয়েছে — এখন শুধু আপনি প্রিভিউতে দেখতে পাবেন।');
    }

    public function destroy(LandingPage $landingPage)
    {
        foreach ($landingPage->sections ?? [] as $section) {
            foreach ($this->imagePathsIn($section['data'] ?? []) as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $landingPage->delete();

        return redirect()->route('tenant.landing-pages.index')->with('success', 'ল্যান্ডিং পেজ মুছে ফেলা হয়েছে।');
    }

    /* ---------------- Sections ---------------- */

    public function addSection(Request $request, LandingPage $landingPage)
    {
        $data = $request->validate(['type' => 'required|string|in:'.implode(',', array_keys(LandingPage::sectionTypes()))]);

        $sections = $landingPage->sections ?? [];
        $sections[] = ['id' => Str::random(10), 'type' => $data['type'], 'data' => LandingPage::blankSectionData($data['type'])];
        $landingPage->update(['sections' => $sections]);

        return back()->with('success', 'সেকশন যোগ হয়েছে।');
    }

    public function updateSection(Request $request, LandingPage $landingPage, string $sectionId)
    {
        $sections = collect($landingPage->sections ?? []);
        $index = $sections->search(fn ($s) => $s['id'] === $sectionId);
        abort_if($index === false, 404);

        $section = $sections[$index];
        $section['data'] = $this->parseSectionData($request, $section['type'], $section['data'] ?? []);
        $sections[$index] = $section;

        $landingPage->update(['sections' => $sections->values()->all()]);

        return back()->with('success', 'সেকশন সেভ হয়েছে।');
    }

    public function destroySection(LandingPage $landingPage, string $sectionId)
    {
        $sections = collect($landingPage->sections ?? []);
        $section = $sections->firstWhere('id', $sectionId);

        foreach ($this->imagePathsIn($section['data'] ?? []) as $path) {
            Storage::disk('public')->delete($path);
        }

        $landingPage->update(['sections' => $sections->reject(fn ($s) => $s['id'] === $sectionId)->values()->all()]);

        return back()->with('success', 'সেকশন মুছে ফেলা হয়েছে।');
    }

    public function moveSection(Request $request, LandingPage $landingPage, string $sectionId)
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];

        $sections = collect($landingPage->sections ?? [])->values();
        $index = $sections->search(fn ($s) => $s['id'] === $sectionId);
        abort_if($index === false, 404);

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith >= 0 && $swapWith < $sections->count()) {
            $tmp = $sections[$index];
            $sections[$index] = $sections[$swapWith];
            $sections[$swapWith] = $tmp;
            $landingPage->update(['sections' => $sections->values()->all()]);
        }

        return back();
    }

    public function duplicateSection(LandingPage $landingPage, string $sectionId)
    {
        $sections = collect($landingPage->sections ?? [])->values();
        $index = $sections->search(fn ($s) => $s['id'] === $sectionId);
        abort_if($index === false, 404);

        $copy = $sections[$index];
        $copy['id'] = Str::random(10);
        $sections->splice($index + 1, 0, [$copy]);

        $landingPage->update(['sections' => $sections->values()->all()]);

        return back()->with('success', 'সেকশন কপি হয়েছে।');
    }

    /* ---------------- Section data parsing ---------------- */

    protected function parseSectionData(Request $request, string $type, array $current): array
    {
        $folder = 'landing-pages/'.app('currentTenant')->id;
        $in = $request->input('data', []);
        $files = $request->file('data', []);

        $image = fn (string $field, ?string $existing) => isset($files[$field]) && $files[$field]->isValid()
            ? tap($files[$field]->store($folder, 'public'), fn () => $existing && Storage::disk('public')->delete($existing))
            : $existing;

        return match ($type) {
            'hero' => [
                'headline' => (string) ($in['headline'] ?? ''),
                'subheadline' => (string) ($in['subheadline'] ?? ''),
                'image_path' => $image('image', $current['image_path'] ?? null),
                'video_url' => (string) ($in['video_url'] ?? ''),
                'cta_text' => (string) ($in['cta_text'] ?? 'এখনই অর্ডার করুন'),
            ],
            'media' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'video_url' => (string) ($in['video_url'] ?? ''),
            ],
            'benefits' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['title'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'icon' => (string) ($i['icon'] ?? '✅'),
                        'title' => (string) $i['title'],
                        'description' => (string) ($i['description'] ?? ''),
                    ])->values()->all(),
            ],
            'image_text' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
                'layout' => in_array($in['layout'] ?? '', ['image-left', 'image-right']) ? $in['layout'] : 'image-left',
            ],
            'features' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
            ],
            'gallery' => [
                'images' => collect($current['images'] ?? [])
                    ->reject(fn ($path, $i) => $request->boolean("data.remove_image_$i"))
                    ->values()
                    ->concat(
                        collect($request->file('gallery_images', []))
                            ->filter(fn ($f) => $f && $f->isValid())
                            ->map(fn ($f) => $f->store($folder, 'public'))
                    )
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'reviews' => [
                'heading' => (string) ($in['heading'] ?? ''),
                // filter() keeps original keys — map() runs BEFORE values(),
                // so $idx below still lines up with the form's original
                // slot index (files/current are keyed the same way the form
                // rendered each slot, 0..5), unlike a post-filter reindex.
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['customer_name'] ?? '') !== '')
                    ->map(function ($i, $idx) use ($files, $folder, $current) {
                        $existingPhoto = $current['items'][$idx]['photo_path'] ?? null;
                        $file = $files['items'][$idx]['photo'] ?? null;

                        return [
                            'customer_name' => (string) $i['customer_name'],
                            'review_text' => (string) ($i['review_text'] ?? ''),
                            'rating' => max(1, min(5, (int) ($i['rating'] ?? 5))),
                            'photo_path' => ($file && $file->isValid()) ? $file->store($folder, 'public') : $existingPhoto,
                        ];
                    })->values()->all(),
            ],
            'video_reviews' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['video_url'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'customer_name' => (string) ($i['customer_name'] ?? ''),
                        'video_url' => (string) $i['video_url'],
                    ])->values()->all(),
            ],
            'cta' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'button_text' => (string) ($in['button_text'] ?? 'এখনই অর্ডার করুন'),
            ],
            'faq' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['question'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'question' => (string) $i['question'],
                        'answer' => (string) ($i['answer'] ?? ''),
                    ])->values()->all(),
            ],
            'checkout' => [
                'heading' => (string) ($in['heading'] ?? 'অর্ডার করুন'),
            ],
            default => $current,
        };
    }

    /** Every stored image path in a section's data — used to clean up files on delete. */
    protected function imagePathsIn(array $data): array
    {
        $paths = array_filter([$data['image_path'] ?? null]);

        foreach ($data['images'] ?? [] as $p) {
            $paths[] = $p;
        }

        foreach ($data['items'] ?? [] as $item) {
            if (! empty($item['photo_path'])) {
                $paths[] = $item['photo_path'];
            }
        }

        return $paths;
    }
}
