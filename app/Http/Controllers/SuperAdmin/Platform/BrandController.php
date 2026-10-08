<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandChangeRequest;
use App\Models\PlatformAuditLog;
use App\Support\Platform\BdLocations;
use App\Support\Platform\PlatformNotifier;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin brand management: review queue, approve/reject/suspend/
 * restore/delete, verification, editorial featuring, paid sponsorship and
 * owner change requests. Verification, featuring and sponsorship are three
 * independent switches; none implies another. Every action is audited.
 */
class BrandController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), Brand::STATUSES, true) ? $request->query('status') : null;

        $brands = Brand::with('category', 'owner')->withCount(['changeRequests as pending_changes' => fn ($c) => $c->where('status', 'pending')])
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")->orWhere('founder_name', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($request->filled('category'), fn ($w) => $w->where('brand_category_id', (int) $request->query('category')))
            ->when($request->filled('division'), fn ($w) => $w->where('division', $request->query('division')))
            ->when($request->query('flag') === 'verified', fn ($w) => $w->where('is_verified', true))
            ->when($request->query('flag') === 'unverified', fn ($w) => $w->where('is_verified', false))
            ->when($request->query('flag') === 'featured', fn ($w) => $w->where('is_featured', true))
            ->when($request->query('flag') === 'sponsored', fn ($w) => $w->where('is_sponsored', true))
            ->when($request->query('flag') === 'changes', fn ($w) => $w->whereHas('changeRequests', fn ($c) => $c->where('status', 'pending')))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()
            ->paginate(25)->withQueryString();

        return view('super.platform.brands.index', [
            'brands' => $brands,
            'counts' => Brand::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'pendingChanges' => BrandChangeRequest::where('status', 'pending')->whereHas('brand')->count(),
            'categories' => BrandCategory::ordered()->get(),
            'divisions' => BdLocations::divisions(),
        ]);
    }

    public function create()
    {
        return view('super.platform.brands.form', $this->formData(new Brand(['status' => 'approved'])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $brand = new Brand($data);
        $brand->status = $request->input('status') === 'pending' ? 'pending' : 'approved';
        if ($request->hasFile('logo')) {
            $brand->logo_path = $request->file('logo')->store('brands/logos', 'public');
        }
        $brand->save();

        if ($brand->status === 'approved') {
            $brand->assignSlug();
            $brand->approved_at = now();
            $brand->save();
        }

        PlatformAuditLog::record('brand.created_by_admin', $brand, ['name' => $brand->name, 'status' => $brand->status]);

        return redirect()->route('super.brands.show', $brand)->with('success', 'Brand added.');
    }

    public function show(Brand $brand)
    {
        $brand->load('category', 'owner', 'pendingChange', 'nominations.award', 'nominations.category', 'recognitions.award', 'voteEntries.campaign', 'voteEntries.category');

        return view('super.platform.brands.show', [
            'brand' => $brand,
            'categories' => BrandCategory::ordered()->pluck('name', 'id'),
            'audit' => PlatformAuditLog::where('subject_type', 'Brand')->where('subject_id', $brand->id)->latest('id')->take(30)->get(),
        ]);
    }

    public function edit(Brand $brand)
    {
        return view('super.platform.brands.form', $this->formData($brand));
    }

    public function update(Request $request, Brand $brand)
    {
        $data = $this->validated($request, $brand);
        if ($request->hasFile('logo')) {
            if ($brand->logo_path) {
                Storage::disk('public')->delete($brand->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('brands/logos', 'public');
        }

        $brand->fill($data);
        $dirty = $brand->getDirty();
        $before = array_intersect_key($brand->getOriginal(), $dirty);
        $brand->save();

        if ($dirty) {
            PlatformAuditLog::record('brand.edited', $brand, ['before' => $before, 'after' => $dirty]);
        }

        return redirect()->route('super.brands.show', $brand)->with('success', 'Brand updated.');
    }

    public function destroy(Request $request, Brand $brand)
    {
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];

        DB::transaction(function () use ($brand, $reason) {
            // Leave active campaigns cleanly; vote history stays for audit.
            $brand->voteEntries()->update(['is_active' => false]);
            PlatformAuditLog::record('brand.deleted', $brand, ['name' => $brand->name, 'slug' => $brand->slug], $reason);
            $brand->delete();
        });

        return redirect()->route('super.brands.index')->with('success', 'Brand deleted. Its public link is now retired.');
    }

    /** approve | reject | suspend | restore */
    public function status(Request $request, Brand $brand)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,suspend,restore',
            'reason' => 'nullable|string|max:500',
        ]);
        $action = $data['action'];
        $reason = $data['reason'] ?? null;

        if (in_array($action, ['reject', 'suspend'], true) && ! $reason) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason — the owner will see it.']);
        }

        $from = $brand->status;
        $allowed = [
            'approve' => ['pending', 'rejected'],
            'reject' => ['pending'],
            'suspend' => ['approved'],
            'restore' => ['suspended'],
        ];
        if (! in_array($from, $allowed[$action], true)) {
            return back()->with('error', "Can't {$action} a brand that is {$from}.");
        }

        if ($action === 'approve' || $action === 'restore') {
            $brand->status = 'approved';
            $brand->status_reason = null;
            $brand->approved_at ??= now();
            $brand->assignSlug();
        } else {
            $brand->status = $action === 'reject' ? 'rejected' : 'suspended';
            $brand->status_reason = $reason;
        }
        $brand->save();

        if ($action === 'suspend') {
            $brand->voteEntries()->update(['is_active' => false]);
        }

        PlatformAuditLog::record('brand.'.$action, $brand, ['status' => [$from, $brand->status]], $reason);

        [$type, $title, $body] = match ($action) {
            'approve' => ['brand_approved', 'Your brand is live on MetaSoft BD 🎉', $brand->name.' has been approved. Your public profile: '.$brand->profileUrl()],
            'reject' => ['brand_rejected', 'Your brand registration needs changes', 'Reason: '.$reason.' — update your profile and resubmit it from your dashboard.'],
            'suspend' => ['brand_suspended', 'Your brand profile has been suspended', 'Reason: '.$reason],
            'restore' => ['brand_restored', 'Your brand profile is visible again', $brand->name.' has been restored.'],
        };
        PlatformNotifier::owner($brand, $type, $title, $body, route('owner.dashboard'));

        return back()->with('success', 'Brand '.$brand->status.'.');
    }

    public function verification(Request $request, Brand $brand)
    {
        $verified = $request->boolean('verified');
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        if ($verified && $brand->status !== 'approved') {
            return back()->with('error', 'Only approved brands can be verified.');
        }
        if ($brand->is_verified === $verified) {
            return back();
        }

        $brand->update(['is_verified' => $verified, 'verified_at' => $verified ? now() : null]);
        PlatformAuditLog::record($verified ? 'brand.verified' : 'brand.unverified', $brand, ['is_verified' => [! $verified, $verified]], $reason);
        PlatformNotifier::owner($brand, 'verification_changed',
            $verified ? 'Your brand is now MetaSoft BD Verified' : 'Your brand’s verified badge was removed',
            $verified ? 'The verified badge now appears next to '.$brand->name.' across the platform.' : ($reason ? 'Reason: '.$reason : null),
            route('owner.dashboard'));

        return back()->with('success', $verified ? 'Brand verified.' : 'Verification removed.');
    }

    /** Editorial pick — never sold. */
    public function featured(Request $request, Brand $brand)
    {
        $data = $request->validate(['featured' => 'required|boolean', 'featured_order' => 'nullable|integer|min:0|max:9999']);
        if ($data['featured'] && $brand->status !== 'approved') {
            return back()->with('error', 'Only approved brands can be featured.');
        }

        $before = ['is_featured' => $brand->is_featured, 'featured_order' => $brand->featured_order];
        $brand->update(['is_featured' => (bool) $data['featured'], 'featured_order' => (int) ($data['featured_order'] ?? $brand->featured_order)]);
        PlatformAuditLog::record($data['featured'] ? 'brand.featured' : 'brand.unfeatured', $brand, ['before' => $before]);

        return back()->with('success', $data['featured'] ? 'Brand featured.' : 'Brand removed from featured.');
    }

    /** Paid placement — always labelled "Sponsored", never touches recognition. */
    public function sponsorship(Request $request, Brand $brand)
    {
        $data = $request->validate(['sponsored' => 'required|boolean', 'sponsored_until' => 'nullable|date|after_or_equal:today']);

        $brand->update(['is_sponsored' => (bool) $data['sponsored'], 'sponsored_until' => $data['sponsored'] ? ($data['sponsored_until'] ?? null) : null]);
        PlatformAuditLog::record($data['sponsored'] ? 'brand.sponsored' : 'brand.unsponsored', $brand, ['until' => $brand->sponsored_until?->toDateString()]);

        return back()->with('success', $data['sponsored'] ? 'Sponsored placement on.' : 'Sponsored placement off.');
    }

    public function resetOwnerPassword(Brand $brand)
    {
        $owner = $brand->owner ?? abort(404);
        $password = Str::password(10, symbols: false);
        $owner->update(['password' => $password]);
        PlatformAuditLog::record('owner.password_reset', $brand, ['owner_id' => $owner->id]);

        return back()->with('success', 'New password for '.$owner->email.': '.$password.' — share it with the owner privately; they can change it under Account.');
    }

    public function ownerStatus(Request $request, Brand $brand)
    {
        $owner = $brand->owner ?? abort(404);
        $status = $request->validate(['status' => 'required|in:active,suspended'])['status'];
        $owner->update(['status' => $status]);
        PlatformAuditLog::record('owner.'.($status === 'active' ? 'activated' : 'suspended'), $brand, ['owner_id' => $owner->id]);

        return back()->with('success', 'Owner login '.$status.'.');
    }

    public function changes()
    {
        return view('super.platform.brands.changes', [
            'requests' => BrandChangeRequest::with('brand.category')->where('status', 'pending')->whereHas('brand')->oldest()->paginate(25),
            'categories' => BrandCategory::pluck('name', 'id'),
        ]);
    }

    public function approveChange(BrandChangeRequest $change)
    {
        if ($change->status !== 'pending') {
            return back();
        }
        $brand = $change->brand;
        $changes = $change->changes;

        if (isset($changes['name']) && Brand::nameTaken($changes['name'], $brand->id)) {
            return back()->with('error', 'Another brand already uses the requested name.');
        }
        if (isset($changes['logo_path']) && $brand->logo_path && $brand->logo_path !== $changes['logo_path']) {
            Storage::disk('public')->delete($brand->logo_path);
        }

        DB::transaction(function () use ($brand, $change, $changes) {
            $brand->update($changes);
            $change->update(['status' => 'approved', 'reviewed_by' => auth('super_admin')->id(), 'reviewed_at' => now()]);
            PlatformAuditLog::record('brand.change_approved', $brand, ['before' => $change->original, 'after' => $changes]);
        });

        PlatformNotifier::owner($brand, 'change_approved', 'Your profile changes were approved', 'Updated: '.implode(', ', array_keys($changes)), route('owner.brand.edit'));

        return back()->with('success', 'Changes applied.');
    }

    public function rejectChange(Request $request, BrandChangeRequest $change)
    {
        $note = $request->validate(['note' => 'required|string|max:500'])['note'];
        if ($change->status !== 'pending') {
            return back();
        }

        if (! empty($change->changes['logo_path'])) {
            Storage::disk('public')->delete($change->changes['logo_path']);
        }
        $change->update(['status' => 'rejected', 'review_note' => $note, 'reviewed_by' => auth('super_admin')->id(), 'reviewed_at' => now()]);
        PlatformAuditLog::record('brand.change_rejected', $change->brand, ['fields' => array_keys($change->changes)], $note);
        PlatformNotifier::owner($change->brand, 'change_rejected', 'Your profile change request was not approved', 'Reason: '.$note, route('owner.brand.edit'));

        return back()->with('success', 'Change request rejected.');
    }

    private function formData(Brand $brand): array
    {
        return [
            'brand' => $brand,
            'categories' => BrandCategory::ordered()->get(),
            'locations' => BdLocations::map(),
        ];
    }

    private function validated(Request $request, ?Brand $brand): array
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => VoteService::normalizePhone((string) $request->input('phone')) ?? $request->input('phone')]);
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:150',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:160', Rule::unique('brands', 'slug')->ignore($brand?->id)],
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_category_id' => 'required|exists:brand_categories,id',
            'sub_category' => 'nullable|string|max:100',
            'founder_name' => 'required|string|max:150',
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'email' => 'required|email|max:150',
            'division' => 'required|string|max:50',
            'district' => 'required|string|max:50',
            'description' => 'nullable|string|max:2000',
            'products_info' => 'nullable|string|max:3000',
            'founded_year' => 'nullable|integer|min:1900|max:'.now()->year,
            'website' => 'nullable|url|max:255',
            'facebook' => 'nullable|url|max:255',
            'instagram' => 'nullable|url|max:255',
            'tiktok' => 'nullable|url|max:255',
            'youtube' => 'nullable|url|max:255',
        ], ['slug.regex' => 'Use lowercase letters, numbers and single hyphens only.']);

        if (! BdLocations::valid($data['division'], $data['district'])) {
            throw ValidationException::withMessages(['district' => 'Choose a district inside the selected division.']);
        }
        if (Brand::nameTaken($data['name'], $brand?->id) && (! $brand || Brand::nameKey($data['name']) !== $brand->name_key)) {
            throw ValidationException::withMessages(['name' => 'Another brand already uses this name.']);
        }

        unset($data['logo']);
        if (empty($data['slug'])) {
            unset($data['slug']);
        }

        return $data;
    }
}
