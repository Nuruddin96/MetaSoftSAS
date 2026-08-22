<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\CentralAuth\RegisterController;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile login is deliberately NOT a call to Auth::guard('tenant')->attempt()
 * (what TenantAuth\LoginController uses): that guard is a SessionGuard,
 * and API routes have no StartSession middleware — calling ->attempt() here
 * would depend on session state that doesn't exist in a stateless API
 * request. Instead this replicates the exact same credential-check
 * semantics (email match + is_active=1 + password hash match) without the
 * session side effects, then issues a Sanctum token instead of a session
 * cookie. Web login (LoginController) is completely untouched.
 *
 * No `subdomain`/shop field: the web login doesn't have one either — it
 * resolves tenant from the `/shop/{tenant_slug}` URL segment before the
 * form is ever shown (see ResolveTenant), and the mobile app has no
 * per-tenant URL to play that role. Since `users.email` is only unique
 * per-tenant, not globally (uq_tenant_email(tenant_id,email) — confirmed
 * against schema.sql), a bare `where('email', ...)->first()` would pick an
 * arbitrary tenant's row whenever the same email exists at more than one
 * tenant, independent of whether the submitted password actually matches
 * that row. So this checks the password against EVERY row sharing the
 * email (currentTenant is deliberately not bound yet, so BelongsToTenant's
 * global scope is a no-op here — same mechanism the old subdomain-first
 * version relied on) and only succeeds when exactly one row's hash
 * matches. Tenant is then resolved from that single matched user's own
 * `tenant_id`, the same way `me()` below already does it post-auth.
 */
class AuthController extends Controller
{
    /**
     * Same validation, subdomain generation, and Tenant+User creation as
     * `CentralAuth\RegisterController::store()` (reuses its
     * `makeSubdomain()` helper directly rather than duplicating the
     * slug/uniqueness logic) — a mobile signup must create the exact same
     * kind of tenant a web signup does. Deliberately omits the web
     * controller's affiliate-referral (`?ref=`) handling: that's a
     * marketing-link feature with no equivalent entry point in the mobile
     * app, not a piece of the core registration business logic. Returns
     * the same `{token, user, tenant}` shape as `login()` so the client
     * can reuse one response model; a freshly created tenant naturally
     * has `needs_onboarding: true` (`onboarding_completed_at` starts
     * NULL), which is what routes it into the Onboarding Wizard.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_phone' => ['required', 'regex:/^01[3-9][0-9]{8}$/'],
            'owner_email' => ['required', 'email', 'unique:tenants,owner_email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'owner_phone.regex' => 'সঠিক বাংলাদেশি মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
            'owner_email.unique' => 'এই ইমেইলে আগেই একাউন্ট খোলা হয়েছে।',
            'password.confirmed' => 'দুইবার দেয়া পাসওয়ার্ড মিলছে না।',
        ]);

        $subdomain = RegisterController::makeSubdomain($data['store_name']);
        if ($subdomain === null) {
            throw ValidationException::withMessages([
                'store_name' => ['ওয়েবসাইট ঠিকানা তৈরির জন্য বিজনেসের নামটি ইংরেজি অক্ষরে লিখুন (যেমন: Rahim Fashion House)।'],
            ]);
        }

        [$user, $tenant] = DB::transaction(function () use ($data, $subdomain) {
            $tenant = Tenant::create([
                'store_name' => $data['store_name'],
                'subdomain' => $subdomain,
                'owner_name' => $data['owner_name'],
                'owner_phone' => $data['owner_phone'],
                'owner_email' => $data['owner_email'],
                'status' => 'trial',
                'plan_id' => 1,
            ]);

            $user = User::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone' => $data['owner_phone'],
                'password' => Hash::make($data['password']),
                'role' => 'owner',
            ]);

            return [$user, $tenant];
        });

        app()->instance('currentTenant', $tenant);

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'business_name' => $tenant->store_name,
                'plan_name' => $tenant->plan?->name,
                'plan_expires_at' => optional($tenant->subscription_ends_at)->toIso8601String(),
                'needs_onboarding' => $tenant->needsOnboarding(),
                'onboarding_step' => $tenant->needsOnboarding() ? ($tenant->onboarding_step ?: 'business_type') : null,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $matches = User::where('email', $data['email'])
            ->where('is_active', 1)
            ->get()
            ->filter(fn (User $candidate) => Hash::check($data['password'], $candidate->password));

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['email' => ['ইমেইল বা পাসওয়ার্ড সঠিক নয়।']]);
        }

        // Same email+password valid at more than one tenant — genuinely
        // ambiguous which shop was intended. The mobile app has no shop
        // picker, so fail closed rather than guess (see AuthController's
        // class docblock).
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['email' => ['একাধিক দোকানে এই তথ্য মিলেছে। সাপোর্টে যোগাযোগ করুন।']]);
        }

        $user = $matches->first();
        $tenant = $user->tenant;

        app()->instance('currentTenant', $tenant);

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'business_name' => $tenant->store_name,
                'plan_name' => $tenant->plan?->name,
                'plan_expires_at' => optional($tenant->subscription_ends_at)->toIso8601String(),
                // Tenant Onboarding Wizard — see Api\Mobile\OnboardingController
                // and App\Services\Tenant\TenantOnboardingService. false/null
                // for every tenant that existed before this feature shipped
                // (backfilled onboarding_completed_at, see chunk52.sql).
                'needs_onboarding' => $tenant->needsOnboarding(),
                'onboarding_step' => $tenant->needsOnboarding() ? ($tenant->onboarding_step ?: 'business_type') : null,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'business_name' => $tenant->store_name,
                'plan_name' => $tenant->plan?->name,
                'plan_expires_at' => optional($tenant->subscription_ends_at)->toIso8601String(),
                // Tenant Onboarding Wizard — see Api\Mobile\OnboardingController
                // and App\Services\Tenant\TenantOnboardingService. false/null
                // for every tenant that existed before this feature shipped
                // (backfilled onboarding_completed_at, see chunk52.sql).
                'needs_onboarding' => $tenant->needsOnboarding(),
                'onboarding_step' => $tenant->needsOnboarding() ? ($tenant->onboarding_step ?: 'business_type') : null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'লগআউট সম্পন্ন হয়েছে।']);
    }
}
