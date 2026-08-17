<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Page;
use App\Models\Review;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WebsiteController extends Controller
{
    /** Everything the storefront can be customised with */
    public const KEYS = [
        'announcement', 'announcement_style', 'hero_style',
        'footer_about', 'footer_phone', 'footer_email', 'footer_address', 'footer_note',
        'social_facebook', 'social_instagram', 'social_youtube', 'social_tiktok',
        'whatsapp_number', 'show_whatsapp_float',
        'show_featured', 'featured_title', 'show_categories',
    ];

    public function index()
    {
        return view('tenant.website.index', [
            'tenant' => app('currentTenant'),
            'set' => StoreSetting::pluck('value', 'key'),
            'banners' => Banner::orderBy('sort_order')->get(),
            'pages' => Page::orderBy('sort_order')->get(),
            'reviews' => Review::tablesReady() ? Review::orderBy('sort_order')->get() : collect(),
        ]);
    }

    /** Logo, colours, announcement bar */
    public function brand(Request $request)
    {
        $data = $request->validate([
            'store_name' => 'required|string|max:150',
            'primary_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo' => 'nullable|image|max:2048',
            'announcement' => 'nullable|string|max:200',
            'announcement_style' => 'nullable|in:static,marquee',
        ]);

        $tenant = app('currentTenant');

        $update = [
            'store_name' => $data['store_name'],
            'primary_color' => $data['primary_color'],
            'secondary_color' => $data['secondary_color'] ?? $tenant->secondary_color,
        ];

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('public')->delete($tenant->logo_path);
            }
            $update['logo_path'] = $request->file('logo')->store('branding/'.$tenant->id, 'public');
        }

        $tenant->update($update);

        $this->put('announcement', $data['announcement'] ?? null);
        $this->put('announcement_style', $data['announcement_style'] ?? 'static');

        return back()->with('success', 'ব্র্যান্ড সেটিংস সেভ হয়েছে।');
    }

    public function removeLogo()
    {
        $tenant = app('currentTenant');

        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $tenant->update(['logo_path' => null]);
        }

        return back()->with('success', 'লোগো সরানো হয়েছে।');
    }

    /** Homepage layout toggles */
    public function homepage(Request $request)
    {
        $data = $request->validate([
            'featured_title' => 'nullable|string|max:100',
            'hero_style' => 'nullable|in:slider,simple,none',
        ]);

        $this->put('featured_title', $data['featured_title'] ?? null);
        $this->put('hero_style', $data['hero_style'] ?? 'slider');
        $this->put('show_featured', $request->boolean('show_featured') ? '1' : '0');
        $this->put('show_categories', $request->boolean('show_categories') ? '1' : '0');

        return back()->with('success', 'হোম পেজ সেটিংস সেভ হয়েছে।');
    }

    /** Footer + social + whatsapp */
    public function footer(Request $request)
    {
        $data = $request->validate([
            'footer_about' => 'nullable|string|max:500',
            'footer_phone' => 'nullable|string|max:50',
            'footer_email' => 'nullable|string|max:100',
            'footer_address' => 'nullable|string|max:255',
            'footer_note' => 'nullable|string|max:255',
            'social_facebook' => 'nullable|url|max:255',
            'social_instagram' => 'nullable|url|max:255',
            'social_youtube' => 'nullable|url|max:255',
            'social_tiktok' => 'nullable|url|max:255',
            'whatsapp_number' => 'nullable|string|max:20',
        ]);

        foreach ($data as $key => $value) {
            $this->put($key, $value);
        }

        $this->put('show_whatsapp_float', $request->boolean('show_whatsapp_float') ? '1' : '0');

        return back()->with('success', 'ফুটার সেটিংস সেভ হয়েছে।');
    }

    /* ---------------- Banners ---------------- */

    public function storeBanner(Request $request)
    {
        $data = $request->validate([
            'image' => 'required|image|max:4096',
            'title' => 'nullable|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'button_text' => 'nullable|string|max:50',
            'button_link' => 'nullable|string|max:255',
        ]);

        $tenant = app('currentTenant');

        Banner::create([
            'image_path' => $request->file('image')->store('banners/'.$tenant->id, 'public'),
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'button_text' => $data['button_text'] ?? null,
            'button_link' => $data['button_link'] ?? null,
            'sort_order' => (int) Banner::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return back()->with('success', 'ব্যানার যোগ হয়েছে।');
    }

    public function destroyBanner(Banner $banner)
    {
        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $banner->delete();

        return back()->with('success', 'ব্যানার মুছে ফেলা হয়েছে।');
    }

    /* ---------------- Pages ---------------- */

    public function storePage(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            // Distinct from `title` (the nav-link label) — the on-page <h1>
            // heading (database/sql/chunk43.sql). Blank falls back to
            // `title` — see resources/views/storefront/page.blade.php.
            'page_header' => 'nullable|string|max:200',
            'content' => 'nullable|string|max:50000',
        ]);

        Page::create([
            'title' => $data['title'],
            'page_header' => $data['page_header'] ?? null,
            'content' => $data['content'] ?? null,
            'show_in_footer' => $request->boolean('show_in_footer', true),
            'show_in_header' => $request->boolean('show_in_header'),
            'sort_order' => (int) Page::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return back()->with('success', 'পেজ তৈরি হয়েছে।');
    }

    public function editPage(Page $page)
    {
        return view('tenant.website.page-form', compact('page'));
    }

    public function updatePage(Request $request, Page $page)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'page_header' => 'nullable|string|max:200',
            'content' => 'nullable|string|max:50000',
        ]);

        $page->update([
            'title' => $data['title'],
            'page_header' => $data['page_header'] ?? null,
            'content' => $data['content'] ?? null,
            'show_in_footer' => $request->boolean('show_in_footer'),
            'show_in_header' => $request->boolean('show_in_header'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('tenant.website')->with('success', 'পেজ আপডেট হয়েছে।');
    }

    public function destroyPage(Page $page)
    {
        $page->delete();

        return back()->with('success', 'পেজ মুছে ফেলা হয়েছে।');
    }

    /* ---------------- Reviews ---------------- */

    public function storeReview(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'photo' => 'nullable|image|max:2048',
            'review_text' => 'nullable|string|max:500',
        ]);

        $tenant = app('currentTenant');

        Review::create([
            'customer_name' => $data['customer_name'],
            'photo_path' => $request->hasFile('photo') ? $request->file('photo')->store('reviews/'.$tenant->id, 'public') : null,
            'review_text' => $data['review_text'] ?? null,
            'sort_order' => (int) Review::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return back()->with('success', 'রিভিউ যোগ হয়েছে।');
    }

    public function updateReview(Request $request, Review $review)
    {
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
            $update['photo_path'] = $request->file('photo')->store('reviews/'.app('currentTenant')->id, 'public');
        }

        $review->update($update);

        return back()->with('success', 'রিভিউ আপডেট হয়েছে।');
    }

    public function destroyReview(Review $review)
    {
        if ($review->photo_path) {
            Storage::disk('public')->delete($review->photo_path);
        }
        $review->delete();

        return back()->with('success', 'রিভিউ মুছে ফেলা হয়েছে।');
    }

    protected function put(string $key, ?string $value): void
    {
        StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
