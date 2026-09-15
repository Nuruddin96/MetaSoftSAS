<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use App\Services\LandingPage\DesignResolver;
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
    public function __construct(protected SectionDataService $sectionData, protected DesignResolver $design) {}

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
            'templates' => config('landing_templates'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'product_id' => 'required|integer|exists:products,id',
            'template' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('landing_templates')))],
        ]);

        $product = Product::with('variants')->findOrFail($data['product_id']);
        $templateKey = $data['template'] ?? 'default';

        $landingPage = LandingPage::create([
            'title' => $data['title'],
            'product_id' => $product->id,
            'status' => 'draft',
            'sections' => LandingPage::defaultSections($product, $templateKey),
            'design' => config("landing_templates.{$templateKey}.design"),
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

    /** Global "Design" tab — brand/typography/buttons/global tokens (Phase 1). */
    public function design(LandingPage $landingPage)
    {
        return view('tenant.landing-pages.design', [
            'tenant' => app('currentTenant'),
            'landingPage' => $landingPage,
            'resolved' => $this->design->resolveGlobal($landingPage->design, app('currentTenant')),
        ]);
    }

    public function updateDesign(Request $request, LandingPage $landingPage)
    {
        $hex = fn (?string $v) => ($v && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
        $in = $request->input('design', []);
        $enum = fn ($v, array $allowed, string $default) => in_array($v, $allowed, true) ? $v : $default;

        $landingPage->update(['design' => [
            'brand' => [
                'primary_color' => $request->boolean('design.brand.primary_color_enabled') ? $hex($in['brand']['primary_color'] ?? null) : null,
                'secondary_color' => $request->boolean('design.brand.secondary_color_enabled') ? $hex($in['brand']['secondary_color'] ?? null) : null,
                'background_color' => $request->boolean('design.brand.background_color_enabled') ? $hex($in['brand']['background_color'] ?? null) : null,
                'text_color' => $request->boolean('design.brand.text_color_enabled') ? $hex($in['brand']['text_color'] ?? null) : null,
            ],
            'typography' => [
                'heading_font' => $enum($in['typography']['heading_font'] ?? null, ['display', 'body', 'modern'], 'display'),
                'body_font' => $enum($in['typography']['body_font'] ?? null, ['display', 'body', 'modern'], 'body'),
                'font_size' => $enum($in['typography']['font_size'] ?? null, ['sm', 'base', 'lg'], 'base'),
                'font_weight' => $enum($in['typography']['font_weight'] ?? null, ['normal', 'semibold', 'bold'], 'normal'),
                'line_height' => $enum($in['typography']['line_height'] ?? null, ['tight', 'normal', 'relaxed'], 'normal'),
            ],
            'buttons' => [
                'style' => $enum($in['buttons']['style'] ?? null, ['solid', 'outline', 'ghost'], 'solid'),
                'radius' => $enum($in['buttons']['radius'] ?? null, ['none', 'md', 'full'], 'md'),
                'size' => $enum($in['buttons']['size'] ?? null, ['sm', 'md', 'lg'], 'md'),
            ],
            'global' => [
                'container_width' => $enum($in['global']['container_width'] ?? null, ['narrow', 'normal', 'wide'], 'normal'),
                'section_spacing' => $enum($in['global']['section_spacing'] ?? null, ['compact', 'normal', 'spacious'], 'normal'),
                'border_radius' => $enum($in['global']['border_radius'] ?? null, ['none', 'md', 'lg'], 'md'),
                'shadow' => $enum($in['global']['shadow'] ?? null, ['none', 'sm', 'md', 'lg'], 'none'),
            ],
        ]]);

        return back()->with('success', 'ডিজাইন সেভ হয়েছে।');
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
        $sections[] = ['id' => Str::random(10), 'type' => $data['type'], 'hidden' => false, 'data' => LandingPage::blankSectionData($data['type'])];
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

    /** Drag-and-drop reorder (Phase 8) — the up/down arrow buttons (moveSection) stay as a no-JS fallback. */
    public function reorderSections(Request $request, LandingPage $landingPage)
    {
        $order = $request->validate(['order' => 'required|array'])['order'];

        $sections = collect($landingPage->sections ?? []);
        $bySortedId = collect($order)->map(fn ($id) => $sections->firstWhere('id', $id))->filter()->values();

        // Defends against a stale client submitting an order that doesn't
        // name every current section (e.g. someone else deleted one in
        // another tab) — falls back to leaving the order untouched rather
        // than silently dropping a section from the page.
        if ($bySortedId->count() !== $sections->count()) {
            return response()->json(['ok' => false], 422);
        }

        $landingPage->update(['sections' => $bySortedId->values()->all()]);

        return response()->json(['ok' => true]);
    }

    public function toggleSection(LandingPage $landingPage, string $sectionId)
    {
        $sections = collect($landingPage->sections ?? []);
        $index = $sections->search(fn ($s) => $s['id'] === $sectionId);
        abort_if($index === false, 404);

        $section = $sections[$index];
        $section['hidden'] = ! ($section['hidden'] ?? false);
        $sections[$index] = $section;

        $landingPage->update(['sections' => $sections->values()->all()]);

        return back()->with('success', ($section['hidden'] ?? false) ? 'সেকশনটি লুকানো হয়েছে।' : 'সেকশনটি আবার দেখানো হচ্ছে।');
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
