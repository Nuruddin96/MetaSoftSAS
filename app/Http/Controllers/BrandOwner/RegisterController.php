<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Support\Platform\BdLocations;
use App\Support\Platform\PlatformNotifier;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "List Your Brand" — self-service registration that replaces sending
 * brand details over WhatsApp. Creates the owner login and the brand in
 * one step; the brand starts as `pending` and is only published after
 * Super Admin approval. Only core identity fields are required.
 */
class RegisterController extends Controller
{
    public function show()
    {
        if (Auth::guard('brand_owner')->check()) {
            return redirect()->route('owner.dashboard');
        }

        return view('brand-owner.register', [
            'categories' => BrandCategory::active()->ordered()->get(),
            'locations' => BdLocations::map(),
        ]);
    }

    public function store(Request $request)
    {
        // Honeypot: real people never see or fill this field.
        if (filled($request->input('company_website'))) {
            return redirect()->route('owner.register');
        }

        $request->merge(['phone' => VoteService::normalizePhone((string) $request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'brand_name' => 'required|string|min:2|max:150',
            'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_category_id' => ['required', Rule::exists('brand_categories', 'id')->where('is_active', 1)],
            'founder_name' => 'required|string|max:150',
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/', 'unique:brand_owners,phone'],
            'email' => 'required|email:rfc|max:150|unique:brand_owners,email',
            'division' => 'required|string|max:50',
            'district' => 'required|string|max:50',
            'password' => 'required|string|min:8|confirmed',
            'terms' => 'accepted',
            'sub_category' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
            'website' => 'nullable|url|max:255',
            'facebook' => 'nullable|url|max:255',
        ], [
            'phone.regex' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'phone.unique' => 'An account with this phone number already exists. Please log in instead.',
            'email.unique' => 'An account with this email already exists. Please log in instead.',
            'terms.accepted' => 'Please accept the platform rules to continue.',
            'logo.required' => 'Please upload your brand logo.',
        ]);

        if (! BdLocations::valid($data['division'], $data['district'])) {
            throw ValidationException::withMessages(['district' => 'Choose a district inside the selected division.']);
        }

        if (Brand::nameTaken($data['brand_name'])) {
            throw ValidationException::withMessages(['brand_name' => 'A brand with this name is already listed or under review. If it is yours, please contact MetaSoft BD support.']);
        }

        $logoPath = $request->file('logo')->store('brands/logos', 'public');

        [$owner, $brand] = DB::transaction(function () use ($data, $logoPath) {
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
                'facebook' => $data['facebook'] ?? null,
                'status' => 'pending',
            ]);

            return [$owner, $brand];
        });

        Auth::guard('brand_owner')->login($owner, true);
        $request->session()->regenerate();

        PlatformAuditLog::record('brand.registered', $brand, ['name' => $brand->name, 'owner_id' => $owner->id]);
        PlatformNotifier::owner($owner, 'registration_received', 'We received your brand registration',
            'Thank you for listing '.$brand->name.'. Our team reviews every brand — usually within 24 hours. You will be notified here when it is approved.',
            route('owner.dashboard'));
        PlatformNotifier::admins('brand_submitted', 'New brand submitted: '.$brand->name,
            $brand->founder_name.' · '.$brand->district.', '.$brand->division, route('super.brands.show', $brand));

        return redirect()->route('owner.dashboard')->with('success', 'Welcome! Your brand has been submitted for review.');
    }
}
