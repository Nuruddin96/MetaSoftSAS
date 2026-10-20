<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandDirectoryListing;
use App\Models\PlatformAuditLog;
use App\Support\Platform\DirectoryListings;
use App\Support\Platform\PlatformSchema;
use DomainException;
use Illuminate\Http\Request;

/**
 * Super Admin → Directory: the researched discovery listings
 * (BrandDirectoryListing). Replace a listing with its verified registered
 * brand, archive it, or restore it (rollback). Rows are never deleted and
 * every action is audit-logged — rules live in DirectoryListings.
 */
class DirectoryListingController extends Controller
{
    public const FILTERS = ['active', 'claim_pending', 'needs_review', 'archived', 'expired'];

    public function index(Request $request)
    {
        abort_unless(PlatformSchema::directoryReady(), response()->view('super.platform.directory.missing', [], 503));

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : null;
        $today = now()->toDateString();

        $listings = BrandDirectoryListing::with('category', 'claimedBy', 'replacedBy')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('official_domain', 'like', "%{$q}%")))
            ->when($filter === 'active', fn ($w) => $w->where('status', 'active'))
            ->when($filter === 'claim_pending', fn ($w) => $w->where('membership_status', 'claim_pending'))
            ->when($filter === 'needs_review', fn ($w) => $w->where('verification_status', 'needs_review'))
            ->when($filter === 'archived', fn ($w) => $w->where('status', 'archived'))
            ->when($filter === 'expired', fn ($w) => $w->where('status', 'active')->where('expires_at', '<', $today))
            ->orderByRaw("CASE WHEN membership_status = 'claim_pending' THEN 0 ELSE 1 END")->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('super.platform.directory.index', [
            'listings' => $listings,
            'q' => $q,
            'filter' => $filter,
            'counts' => [
                'active' => BrandDirectoryListing::where('status', 'active')->count(),
                'claim_pending' => BrandDirectoryListing::where('membership_status', 'claim_pending')->count(),
                'needs_review' => BrandDirectoryListing::where('verification_status', 'needs_review')->count(),
                'archived' => BrandDirectoryListing::where('status', 'archived')->count(),
                'expired' => BrandDirectoryListing::where('status', 'active')->where('expires_at', '<', $today)->count(),
            ],
            'audit' => PlatformAuditLog::where('subject_type', 'BrandDirectoryListing')->latest('id')->take(20)->get(),
        ]);
    }

    public function replace(Request $request, BrandDirectoryListing $listing, DirectoryListings $directory)
    {
        $data = $request->validate([
            'brand_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);
        $brand = Brand::find($data['brand_id']);
        if (! $brand) {
            return back()->with('error', 'No registered brand #'.$data['brand_id'].'.');
        }

        try {
            $directory->replace($listing, $brand, $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $listing->name.' listing replaced by '.$brand->name.'. Its old link now redirects to the brand profile.');
    }

    public function archive(Request $request, BrandDirectoryListing $listing, DirectoryListings $directory)
    {
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $directory->archive($listing, 'manual', $reason);

        return back()->with('success', $listing->name.' archived. Restore it any time.');
    }

    public function restore(Request $request, BrandDirectoryListing $listing, DirectoryListings $directory)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        $directory->restore($listing, $reason);

        return back()->with('success', $listing->name.' restored.');
    }
}
