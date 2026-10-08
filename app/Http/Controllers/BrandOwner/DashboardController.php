<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\PlatformNotification;
use Illuminate\Http\Request;

/**
 * Brand owner home. Everything is scoped to the signed-in owner's own
 * brand ($owner->brand) — no route here takes a brand id from the request.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user('brand_owner');
        $brand = $owner->brand()->with('category', 'pendingChange')->first();

        $entries = $brand
            ? $brand->voteEntries()->with('campaign', 'category')
                ->whereHas('campaign', fn ($q) => $q->visible())
                ->latest()->get()
            : collect();

        return view('brand-owner.dashboard', [
            'owner' => $owner,
            'brand' => $brand,
            'completion' => $brand?->completion(),
            'entries' => $entries->filter(fn ($e) => in_array($e->campaign->phase(), ['open', 'scheduled', 'paused'], true))->values(),
            'nominations' => $brand ? $brand->nominations()->with('award', 'category')->latest()->take(5)->get() : collect(),
            'recognitions' => $brand ? $brand->recognitions()->with('award', 'category')->latest()->get() : collect(),
            'openAwards' => Award::where('status', 'nominations_open')->count(),
            'notifications' => PlatformNotification::forOwner($owner->id)->latest()->take(5)->get(),
        ]);
    }
}
