<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile login is deliberately NOT a call to Auth::guard('tenant')->attempt()
 * (what TenantAuth\LoginController uses): that guard is a SessionGuard,
 * and API routes have no StartSession middleware — calling ->attempt() here
 * would depend on session state that doesn't exist in a stateless API
 * request. Instead this replicates the exact same credential-check
 * semantics (email match WITHIN the resolved tenant + is_active=1 +
 * password hash match) without the session side effects, then issues a
 * Sanctum token instead of a session cookie. Web login (LoginController)
 * is completely untouched.
 *
 * Tenant resolution: `users.email` is unique per-tenant, not globally
 * (uq_tenant_email(tenant_id,email) — confirmed against schema.sql), so a
 * bare email lookup would be ambiguous across tenants. The client must
 * supply `subdomain` (tenants.subdomain — the same value the web app's own
 * TenantResolver::fromRequest() resolves from the /shop/{tenant_slug} URL
 * segment in path tenancy mode); we look it up the identical way
 * (`Tenant::where('subdomain', strtolower(...))`) before ever touching
 * `users`.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'subdomain' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $tenant = Tenant::where('subdomain', strtolower($data['subdomain']))->first();

        if (! $tenant) {
            throw ValidationException::withMessages(['subdomain' => ['দোকান পাওয়া যায়নি।']]);
        }

        // Bind BEFORE querying users — BelongsToTenant's global scope only
        // filters once currentTenant is bound, and User::creating's
        // tenant_id auto-fill relies on the same binding (unused here since
        // we're not creating, but keeps this path identical to every other
        // tenant-scoped query in the app).
        app()->instance('currentTenant', $tenant);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['ইমেইল বা পাসওয়ার্ড সঠিক নয়।']]);
        }

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
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'লগআউট সম্পন্ন হয়েছে।']);
    }
}
