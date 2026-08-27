<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use App\Services\LandingPage\SectionDataService;
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
    public function __construct(protected SectionDataService $sectionData) {}

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
            foreach ($this->sectionData->imagePathsIn($section['data'] ?? []) as $path) {
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
        $section['data'] = $this->sectionData->parse($request, $section['type'], $section['data'] ?? []);
        $sections[$index] = $section;

        $landingPage->update(['sections' => $sections->values()->all()]);

        return back()->with('success', 'সেকশন সেভ হয়েছে।');
    }

    public function destroySection(LandingPage $landingPage, string $sectionId)
    {
        $sections = collect($landingPage->sections ?? []);
        $section = $sections->firstWhere('id', $sectionId);

        foreach ($this->sectionData->imagePathsIn($section['data'] ?? []) as $path) {
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
}
