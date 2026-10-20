<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Rules\FacebookPageUrl;
use App\Support\Platform\BdLocations;
use App\Support\Platform\DirectoryListings;
use App\Support\Platform\PlatformNotifier;
use App\Support\Platform\PlatformSchema;
use App\Support\Platform\VoteService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "List Your Brand" — self-service registration that replaces sending
 * brand details over WhatsApp. Creates the owner login and the brand in
 * one step; the brand starts as `pending` and is only published after
 * Super Admin approval. Only core identity fields and the brand's Facebook
 * Page link are required. One mobile number = one business profile: the
 * number is normalized (01XXXXXXXXX) before every check, and the unique keys
 * on brand_owners.phone and brands.phone (chunk67.sql) back the check up.
 * An optional cover image is accepted once chunk69.sql (brands.cover_path)
 * is imported; before that the upload box is hidden and a cover is ignored.
 */
class RegisterController extends Controller
{
    public const ACCOUNT_UNAVAILABLE = 'We couldn’t create an account with these details. If you have registered before, please log in or reset your password.';

    public function show()
    {
        if (Auth::guard('brand_owner')->check()) {
            return redirect()->route('owner.dashboard');
        }

        return view('brand-owner.register', [
            'categories' => BrandCategory::active()->ordered()->get(),
            'locations' => BdLocations::map(),
            'coverEnabled' => PlatformSchema::coverReady(),
        ]);
    }

    public function store(Request $request)
    {
        // Honeypot: real people never see or fill this field.
        if (filled($request->input('company_website'))) {
            return redirect()->route('owner.register');
        }

        $request->merge([
            'phone' => VoteService::normalizePhone((string) $request->input('phone')) ?? $request->input('phone'),
            'facebook' => FacebookPageUrl::normalize($request->input('facebook')),
        ]);

        $data = $request->validate([
            'brand_name' => 'required|string|min:2|max:150',
            'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_category_id' => ['required', Rule::exists('brand_categories', 'id')->where('is_active', 1)],
            'founder_name' => 'required|string|max:150',
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'email' => 'required|email:rfc|max:150',
            'division' => 'required|string|max:50',
            'district' => 'required|string|max:50',
            'password' => 'required|string|min:8|confirmed',
            'terms' => 'accepted',
            'sub_category' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
            'website' => 'nullable|url|max:255',
            'facebook' => ['required', 'string', 'max:255', new FacebookPageUrl],
            'instagram' => 'nullable|url|max:255',
            // Same limits as gallery photos (BrandProfileController::addGallery).
            'cover' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
        ], [
            'phone.regex' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'terms.accepted' => 'Please accept the platform rules to continue.',
            'logo.required' => 'Please upload your brand logo.',
            'facebook.required' => 'Add your business’s Facebook Page link.',
        ]);

        if (! BdLocations::valid($data['division'], $data['district'])) {
            throw ValidationException::withMessages(['district' => 'Choose a district inside the selected division.']);
        }

        if (Brand::nameTaken($data['brand_name'])) {
            throw ValidationException::withMessages(['brand_name' => 'A brand with this name is already listed or under review. If it is yours, please contact MetaSoft BD support.']);
        }

        // Duplicate owner accounts get one neutral, field-less answer — it
        // never says whether the email or the phone matched, so the form
        // can't be used to look up who has an account. The unique keys on
        // brand_owners still back this up inside the transaction below.
        $data['email'] = strtolower($data['email']);
        if (BrandOwner::where('email', $data['email'])->orWhere('phone', $data['phone'])->exists()
            || Brand::withTrashed()->where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages(['account' => self::ACCOUNT_UNAVAILABLE]);
        }

        $logoPath = $request->file('logo')->store('brands/logos', 'public');
        $coverPath = PlatformSchema::coverReady() && $request->hasFile('cover')
            ? $request->file('cover')->store('brands/covers', 'public')
            : null;

        try {
            [$owner, $brand] = DB::transaction(fn () => $this->createOwnerAndBrand($data, $logoPath, $coverPath));
        } catch (UniqueConstraintViolationException) {
            // A parallel request registered the same email/phone first (unique keys on brand_owners and brands).
            Storage::disk('public')->delete(array_filter([$logoPath, $coverPath]));
            throw ValidationException::withMessages(['account' => self::ACCOUNT_UNAVAILABLE]);
        }

        Auth::guard('brand_owner')->login($owner, true);
        $request->session()->regenerate();

        PlatformAuditLog::record('brand.registered', $brand, ['name' => $brand->name, 'owner_id' => $owner->id]);
        PlatformNotifier::owner($owner, 'registration_received', 'We received your brand registration',
            'Thank you for listing '.$brand->name.'. Our team reviews every brand — usually within 24 hours. You will be notified here when it is approved.',
            route('owner.dashboard'));
        PlatformNotifier::admins('brand_submitted', 'New brand submitted: '.$brand->name,
            $brand->founder_name.' · '.$brand->district.', '.$brand->division, route('super.brands.show', $brand));
        // Same name or official website as a discovery listing: flag it for review — never linked or replaced automatically.
        app(DirectoryListings::class)->recordClaim($brand);

        return redirect()->route('owner.dashboard')->with('success', 'Welcome! Your brand has been submitted for review.');
    }

    /** @return array{0: BrandOwner, 1: Brand} */
    private function createOwnerAndBrand(array $data, string $logoPath, ?string $coverPath): array
    {
        $owner = BrandOwner::create([
            'name' => $data['founder_name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'],
            'password' => $data['password'],
            'last_login_at' => now(),
        ]);

        $brand = Brand::create([
            'brand_owner_id' => $owner->id,
            'name' => trim($data['brand_name']),
            'brand_category_id' => $data['brand_category_id'],
            'sub_category' => $data['sub_category'] ?? null,
            'founder_name' => $data['founder_name'],
            'phone' => $data['phone'],
            'email' => strtolower($data['email']),
            'division' => $data['division'],
            'district' => $data['district'],
            'logo_path' => $logoPath,
            'description' => $data['description'] ?? null,
            'website' => $data['website'] ?? null,
            'facebook' => $data['facebook'],
            'instagram' => $data['instagram'] ?? null,
            'status' => 'pending',
        ] + ($coverPath ? ['cover_path' => $coverPath] : []));

        return [$owner, $brand];
    }
}
