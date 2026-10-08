<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\AwardCategory;
use App\Models\AwardNomination;
use App\Models\PlatformAuditLog;
use App\Support\Platform\PlatformNotifier;
use Illuminate\Http\Request;

/**
 * Owners see awards, nominate their own (approved) brand while
 * nominations are open, and follow each nomination's status. Shortlisting,
 * finalists and winners are decided by Super Admin only.
 */
class AwardController extends Controller
{
    public function index(Request $request)
    {
        $brand = $request->user('brand_owner')->brand ?? abort(404);

        return view('brand-owner.awards', [
            'brand' => $brand,
            'awards' => Award::public()->with('categories.brandCategory')->latest('year')->get()
                ->sortBy(fn ($a) => $a->status === 'nominations_open' ? 0 : 1)->values(),
            'nominations' => $brand->nominations()->with('award', 'category')->latest()->get(),
            'recognitions' => $brand->recognitions()->with('award', 'category')->latest()->get(),
        ]);
    }

    public function nominate(Request $request)
    {
        $brand = $request->user('brand_owner')->brand ?? abort(404);
        $data = $request->validate([
            'award_category_id' => 'required|integer',
            'statement' => 'nullable|string|max:1500',
        ]);

        $category = AwardCategory::with('award')->findOrFail($data['award_category_id']);

        if (! $brand->isPublished()) {
            return back()->with('error', 'Your brand must be approved before it can be nominated.');
        }
        if (! $category->award->acceptsNominations()) {
            return back()->with('error', 'Nominations for this award are closed.');
        }
        if (! $category->accepts($brand)) {
            return back()->with('error', 'Your brand’s category is not eligible for '.$category->name.'.');
        }
        if (AwardNomination::where('award_category_id', $category->id)->where('brand_id', $brand->id)->exists()) {
            return back()->with('error', 'You have already nominated your brand in this category.');
        }

        $nomination = AwardNomination::create([
            'award_id' => $category->award_id,
            'award_category_id' => $category->id,
            'brand_id' => $brand->id,
            'source' => 'owner',
            'statement' => $data['statement'] ?? null,
            'status' => 'submitted',
        ]);

        PlatformAuditLog::record('nomination.submitted', $nomination, ['brand_id' => $brand->id, 'category' => $category->name]);
        PlatformNotifier::owner($brand, 'nomination_submitted', 'Nomination submitted: '.$category->name,
            'Your nomination for '.$category->award->title.' has been received and will be reviewed.', route('owner.awards'));
        PlatformNotifier::admins('nomination_submitted', 'New nomination: '.$brand->name, $category->award->title.' — '.$category->name, route('super.awards.show', $category->award_id));

        return back()->with('success', 'Nomination submitted. Good luck!');
    }

    public function withdraw(Request $request, int $nomination)
    {
        $brand = $request->user('brand_owner')->brand ?? abort(404);
        // Scoped to the owner's own brand — another brand's nomination 404s.
        $n = $brand->nominations()->whereKey($nomination)->firstOrFail();

        if (! in_array($n->status, ['submitted', 'accepted'], true)) {
            return back()->with('error', 'This nomination can no longer be withdrawn.');
        }

        $n->update(['status' => 'withdrawn']);
        PlatformAuditLog::record('nomination.withdrawn', $n);

        return back()->with('success', 'Nomination withdrawn.');
    }
}
