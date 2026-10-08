<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Read-only voting view for the owner: their entries, totals, rank and
 * the share kit for their permanent vote link (route vote.show, built from
 * the brand's fixed slug — the owner cannot edit it). There is deliberately
 * no write route here: owners can never change votes or rankings.
 */
class VotingController extends Controller
{
    public function index(Request $request)
    {
        $brand = $request->user('brand_owner')->brand ?? abort(404);

        $entries = $brand->voteEntries()
            ->with(['campaign', 'category' => fn ($q) => $q->withSum(['entries as category_votes' => fn ($e) => $e->where('is_active', true)], 'votes_count')])
            ->whereHas('campaign', fn ($q) => $q->visible())
            ->get()
            ->sortByDesc(fn ($e) => [$e->campaign->isOpen(), $e->campaign->ends_at])
            ->values();

        return view('brand-owner.voting', [
            'brand' => $brand,
            'entries' => $entries,
            'voteUrl' => $brand->isPublished() ? $brand->voteUrl() : null,
        ]);
    }
}
