<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Brand;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\VoteCampaign;
use App\Support\Platform\HomepageContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Homepage: the few homepage settings that change over time
 * (announcement, hero text, which award/campaign is shown, sample fill-in,
 * optional sample sections). Brand rows need no setup here: "Newly listed"
 * is automatic from approvals; "Featured" is set per brand (Brands page).
 */
class HomepageController extends Controller
{
    public function edit()
    {
        return view('super.platform.homepage', [
            'settings' => PlatformSetting::get('homepage', HomepageContent::defaults()),
            'defaults' => HomepageContent::defaults(),
            'awards' => Award::public()->latest('year')->get(['id', 'title', 'status', 'is_featured']),
            'campaigns' => VoteCampaign::visible()->latest()->get(['id', 'title', 'status', 'starts_at', 'ends_at']),
            'featured' => Brand::published()->where('is_featured', true)->orderBy('featured_order')->get(['id', 'name', 'featured_order', 'is_verified']),
            'sponsored' => Brand::published()->where('is_sponsored', true)->get(['id', 'name', 'sponsored_until', 'is_sponsored'])->filter->isSponsoredNow(),
            'approvedCount' => Brand::published()->count(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'announcement' => 'required|string|max:160',
            'hero_pill' => 'required|string|max:120',
            'hero_title' => 'required|string|max:120',
            'hero_highlight' => 'nullable|string|max:80',
            'hero_sub' => 'required|string|max:400',
            'award_id' => ['nullable', 'integer', Rule::exists('awards', 'id')],
            'campaign_id' => ['nullable', 'integer', Rule::exists('vote_campaigns', 'id')],
        ]);
        foreach (['sample_fallback', 'show_stories', 'show_events', 'show_sponsors'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['award_id'] = ($data['award_id'] ?? null) ? (int) $data['award_id'] : null;
        $data['campaign_id'] = ($data['campaign_id'] ?? null) ? (int) $data['campaign_id'] : null;
        $data['hero_highlight'] ??= '';

        if (! Schema::hasTable('platform_settings')) {
            return back()->with('error', 'Import database/sql/chunk65.sql first — homepage settings have nowhere to be saved yet.');
        }

        $before = PlatformSetting::get('homepage', HomepageContent::defaults());
        PlatformSetting::put('homepage', $data);
        PlatformAuditLog::record('homepage.updated', new PlatformSetting, [
            'changed' => array_keys(array_diff_assoc(array_map('strval', $data), array_map(fn ($v) => (string) $v, $before))),
        ]);

        return back()->with('success', 'Homepage saved.');
    }

    public function reset()
    {
        if (! Schema::hasTable('platform_settings')) {
            return back()->with('error', 'Import database/sql/chunk65.sql first.');
        }
        PlatformSetting::put('homepage', HomepageContent::defaults());
        PlatformAuditLog::record('homepage.reset', new PlatformSetting);

        return back()->with('success', 'Homepage settings reset to defaults.');
    }
}
