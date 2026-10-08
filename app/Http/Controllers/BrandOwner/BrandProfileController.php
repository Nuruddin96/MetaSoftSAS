<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandChangeRequest;
use App\Models\PlatformAuditLog;
use App\Support\Platform\BdLocations;
use App\Support\Platform\PlatformNotifier;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owner's "My Brand" editor. Optional details save immediately.
 * Identity fields of an approved brand (Brand::reviewFields()) become a
 * BrandChangeRequest that Super Admin approves, so verified public
 * information is never silently replaced. Owners have no way to touch
 * status, verification, featuring, sponsorship or slug.
 */
class BrandProfileController extends Controller
{
    private const GALLERY_MAX = 8;

    public function edit(Request $request)
    {
        $brand = $this->brand($request);

        return view('brand-owner.brand', [
            'brand' => $brand->load('category', 'pendingChange'),
            'categories' => BrandCategory::active()->ordered()->get(),
            'locations' => BdLocations::map(),
            'reviewFields' => $brand->reviewFields(),
            'completion' => $brand->completion(),
            'galleryMax' => self::GALLERY_MAX,
        ]);
    }

    public function update(Request $request)
    {
        $brand = $this->brand($request);
        $request->merge(['phone' => VoteService::normalizePhone((string) $request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'name' => 'required|string|min:2|max:150',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_category_id' => ['required', Rule::exists('brand_categories', 'id')->where('is_active', 1)],
            'sub_category' => 'nullable|string|max:100',
            'founder_name' => 'required|string|max:150',
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'email' => 'required|email:rfc|max:150',
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
        ], ['phone.regex' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);

        if (! BdLocations::valid($data['division'], $data['district'])) {
            throw ValidationException::withMessages(['district' => 'Choose a district inside the selected division.']);
        }
        if (Brand::nameKey($data['name']) !== $brand->name_key && Brand::nameTaken($data['name'], $brand->id)) {
            throw ValidationException::withMessages(['name' => 'Another brand already uses this name.']);
        }

        unset($data['logo']);
        $data['brand_category_id'] = (int) $data['brand_category_id'];
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('brands/logos', 'public');
        }

        $changed = [];
        foreach ($data as $field => $value) {
            $value = $value === '' ? null : $value;
            if ((string) $brand->{$field} !== (string) $value) {
                $changed[$field] = $value;
            }
        }

        $reviewable = array_intersect_key($changed, array_flip($brand->reviewFields()));
        $direct = array_diff_key($changed, $reviewable);

        if ($direct) {
            if (isset($direct['logo_path']) && $brand->logo_path) {
                Storage::disk('public')->delete($brand->logo_path);
            }
            $brand->update($direct);
            PlatformAuditLog::record('brand.owner_updated', $brand, array_keys($direct));
        }

        if ($reviewable) {
            $req = $brand->pendingChange ?? new BrandChangeRequest(['brand_id' => $brand->id, 'status' => 'pending', 'changes' => [], 'original' => []]);
            if (isset($reviewable['logo_path'], $req->changes['logo_path'])) {
                Storage::disk('public')->delete($req->changes['logo_path']);
            }
            $original = $req->original ?? [];
            foreach (array_keys($reviewable) as $f) {
                $original[$f] ??= $brand->{$f};
            }
            $req->changes = array_merge($req->changes ?? [], $reviewable);
            $req->original = $original;
            $req->save();

            PlatformAuditLog::record('brand.change_requested', $brand, array_keys($reviewable));
            PlatformNotifier::admins('change_requested', 'Profile change request: '.$brand->name,
                'Fields: '.implode(', ', array_keys($req->changes)), route('super.brands.show', $brand));
        }

        $msg = match (true) {
            $reviewable && $direct => 'Saved. Some changes (name, logo, category, location, founder or verified contact) are pending review.',
            (bool) $reviewable => 'Your changes were sent for review. The current information stays visible until they are approved.',
            (bool) $direct => 'Your brand profile has been updated.',
            default => 'Nothing changed.',
        };

        return redirect()->route('owner.brand.edit')->with('success', $msg);
    }

    public function addGallery(Request $request)
    {
        $brand = $this->brand($request);
        $request->validate([
            'images' => 'required|array|min:1|max:'.self::GALLERY_MAX,
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:3072',
        ]);

        $gallery = $brand->gallery ?? [];
        $room = self::GALLERY_MAX - count($gallery);
        if ($room <= 0) {
            return back()->with('error', 'Your gallery already has '.self::GALLERY_MAX.' photos. Remove one to add another.');
        }

        foreach (array_slice($request->file('images'), 0, $room) as $file) {
            $gallery[] = $file->store('brands/gallery/'.$brand->id, 'public');
        }
        $brand->update(['gallery' => array_values($gallery)]);

        return back()->with('success', 'Photos added to your gallery.');
    }

    public function removeGallery(Request $request, int $index)
    {
        $brand = $this->brand($request);
        $gallery = $brand->gallery ?? [];
        if (! isset($gallery[$index])) {
            abort(404);
        }

        Storage::disk('public')->delete($gallery[$index]);
        unset($gallery[$index]);
        $brand->update(['gallery' => array_values($gallery)]);

        return back()->with('success', 'Photo removed.');
    }

    /** A rejected brand can be corrected and sent back for review. */
    public function resubmit(Request $request)
    {
        $brand = $this->brand($request);
        if ($brand->status !== 'rejected') {
            return back();
        }

        $brand->update(['status' => 'pending', 'status_reason' => null]);
        PlatformAuditLog::record('brand.resubmitted', $brand);
        PlatformNotifier::admins('brand_submitted', 'Brand resubmitted: '.$brand->name, null, route('super.brands.show', $brand));

        return back()->with('success', 'Your brand has been resubmitted for review.');
    }

    public function cancelChange(Request $request)
    {
        $brand = $this->brand($request);
        $req = $brand->pendingChange;
        if ($req) {
            if (! empty($req->changes['logo_path'])) {
                Storage::disk('public')->delete($req->changes['logo_path']);
            }
            $req->delete();
        }

        return back()->with('success', 'Pending change request cancelled.');
    }

    private function brand(Request $request): Brand
    {
        return $request->user('brand_owner')->brand ?? abort(404, 'No brand is linked to this account.');
    }
}
