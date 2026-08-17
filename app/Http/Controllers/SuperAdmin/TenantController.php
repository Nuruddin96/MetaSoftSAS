<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Domain\CloudflareDomainService;
use App\Services\Domain\DomainManager;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $tenants = Tenant::with('plan')
            ->when($request->q, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('store_name', 'like', '%'.$request->q.'%')
                ->orWhere('subdomain', 'like', '%'.$request->q.'%')
                ->orWhere('owner_phone', 'like', '%'.$request->q.'%')
                ->orWhere('owner_email', 'like', '%'.$request->q.'%')))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()->paginate(25)->withQueryString();

        return view('super.tenants', compact('tenants'));
    }

    public function show(Tenant $tenant)
    {
        return view('super.tenant-show', [
            'tenant' => $tenant->load('plan'),
            'plans' => Plan::orderBy('sort_order')->get(),
            'orders' => Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'staff' => User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'payments' => SubscriptionPayment::where('tenant_id', $tenant->id)->latest()->limit(10)->get(),
            'domainActivationInstructions' => (in_array($tenant->custom_domain_request_status, ['dns_verified', 'approved'], true) && ! $tenant->custom_domain_verified)
                ? DomainManager::driver()->activationInstructions($tenant)
                : null,
        ]);
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);

        return back()->with('success', $tenant->store_name.' সাসপেন্ড করা হয়েছে।');
    }

    public function activate(Tenant $tenant)
    {
        $status = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
            ? 'active'
            : (($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) ? 'trial' : 'expired');

        $tenant->update(['status' => $status]);

        return back()->with('success', $tenant->store_name.' অ্যাক্টিভেট করা হয়েছে ('.$status.')।');
    }

    public function edit(Tenant $tenant)
    {
        return view('super.tenant-edit', ['tenant' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'store_name' => 'required|string|max:150',
            'subdomain' => 'required|string|max:63|regex:/^[a-z0-9-]+$/|unique:tenants,subdomain,'.$tenant->id,
            'owner_name' => 'required|string|max:150',
            'owner_phone' => 'required|string|max:20',
            'owner_email' => 'required|email|unique:tenants,owner_email,'.$tenant->id,
        ], [
            'subdomain.regex' => 'সাবডোমেইনে শুধু ছোট হাতের ইংরেজি অক্ষর, সংখ্যা ও হাইফেন ব্যবহার করা যাবে।',
        ]);

        $tenant->update($data);

        return redirect()->route('super.tenants.show', $tenant)->with('success', 'টেনেন্টের তথ্য আপডেট হয়েছে।');
    }

    /** Reset the tenant owner's panel login password */
    public function resetPassword(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'password' => 'required|min:6|confirmed',
        ]);

        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->first()
            ?? User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $owner) {
            return back()->with('error', 'এই টেনেন্টের কোনো ইউজার একাউন্ট পাওয়া যায়নি।');
        }

        $owner->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', $owner->email.' — এর পাসওয়ার্ড পরিবর্তন করা হয়েছে।');
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();

        return redirect()->route('super.tenants')->with('success', 'টেনেন্ট ও তার সব ডেটা মুছে ফেলা হয়েছে।');
    }

    /**
     * Optional convenience only — NOT required by approveDomain()/
     * activateDomain() below. Kept for an admin who still wants the
     * automated DNS-TXT sanity check; the task this module now follows
     * explicitly forbids requiring/building automatic DNS verification,
     * so this is never surfaced as a mandatory gate anymore.
     */
    public function verifyDomainDns(Tenant $tenant)
    {
        if (! $tenant->custom_domain_requested) {
            return back()->with('error', 'কোনো ডোমেইন রিকোয়েস্ট নেই।');
        }

        if (! DomainManager::driver()->verifyDns($tenant)) {
            return back()->with('error', 'TXT রেকর্ড এখনো পাওয়া যায়নি — DNS propagate হতে সময় লাগতে পারে (২৪-৪৮ ঘণ্টা পর্যন্ত), কিছুক্ষণ পর আবার চেষ্টা করুন।');
        }

        $tenant->update([
            'custom_domain_request_status' => 'dns_verified',
            'custom_domain_dns_verified_at' => now(),
        ]);

        return back()->with('success', 'DNS TXT রেকর্ড যাচাই সফল হয়েছে।');
    }

    /**
     * Step 1 of 2 — staff reviews the request and agrees to proceed. Does
     * NOT touch custom_domain/custom_domain_verified (the columns
     * App\Http\Middleware\ResolveCustomDomain actually routes production
     * traffic on) — the domain only goes live once activateDomain() below
     * is explicitly called, after the admin has manually finished DNS/SSL
     * setup outside this app. Accepts either the legacy 'dns_verified'
     * state (optional convenience — see verifyDomainDns() above) or plain
     * 'pending', since DNS verification is never required to approve a
     * request.
     */
    public function approveDomain(Tenant $tenant)
    {
        if (! in_array($tenant->custom_domain_request_status, ['pending', 'dns_verified'], true)) {
            return back()->with('error', 'অনুমোদনের মতো কোনো পেন্ডিং ডোমেইন রিকোয়েস্ট নেই।');
        }

        $tenant->update(['custom_domain_request_status' => 'approved']);

        return back()->with('success', $tenant->custom_domain_requested.' রিকোয়েস্ট অনুমোদন করা হয়েছে — ম্যানুয়ালি DNS/SSL সেটআপ শেষে Activate করুন।');
    }

    /**
     * "Connect" — dynamic domain mapping via Cloudflare (task Part 21).
     * Kicks off a Cloudflare Custom Hostname for this approved request
     * (App\Services\Domain\CloudflareDomainService). Deliberately does
     * NOT mark the domain Active itself — Cloudflare's DNS-control-
     * validation + SSL issuance is asynchronous, and even once Cloudflare
     * succeeds this app still independently verifies the domain actually
     * reaches THIS tenant's storefront before ever touching
     * custom_domain_verified — see refreshDomainConnection() below.
     */
    public function connectDomain(Tenant $tenant, CloudflareDomainService $cloudflare)
    {
        if (! $tenant->custom_domain_requested) {
            return back()->with('error', 'কোনো ডোমেইন রিকোয়েস্ট নেই।');
        }

        if (! in_array($tenant->custom_domain_request_status, ['approved', 'dns_verified'], true)) {
            return back()->with('error', 'আগে রিকোয়েস্টটি Approve করুন।');
        }

        if (! Tenant::cloudflareDomainColumnsReady()) {
            return back()->with('error', 'এই ফিচারের জন্য প্রয়োজনীয় ডেটাবেজ কলাম এখনো ইম্পোর্ট করা হয়নি।');
        }

        $result = $cloudflare->createCustomHostname($tenant->custom_domain_requested);

        if (! $result->configured) {
            $tenant->update(['custom_domain_connect_status' => 'dns_required', 'custom_domain_connect_error' => null]);

            return back()->with('error', 'Cloudflare কনফিগার করা নেই — নিচে ম্যানুয়াল DNS নির্দেশনা দেখানো হচ্ছে। CLOUDFLARE_API_TOKEN/CLOUDFLARE_ZONE_ID সেট করলে এটি স্বয়ংক্রিয় হবে।');
        }

        if (! $result->successful) {
            $tenant->update(['custom_domain_connect_status' => 'failed', 'custom_domain_connect_error' => $result->errorMessage]);

            return back()->with('error', 'Cloudflare কানেকশন ব্যর্থ হয়েছে: '.$result->errorMessage);
        }

        $tenant->update([
            'cf_custom_hostname_id' => $result->id,
            'custom_domain_connect_status' => $result->active ? 'connected' : 'connecting',
            'custom_domain_connect_error' => null,
        ]);

        return back()->with('success', 'Cloudflare-এ কানেকশন শুরু হয়েছে — DNS/SSL সম্পন্ন হতে কিছুক্ষণ সময় লাগতে পারে। কিছুক্ষণ পর "স্ট্যাটাস রিফ্রেশ করুন" চাপুন।');
    }

    /**
     * "Refresh status" — re-checks Cloudflare, and only once Cloudflare
     * itself reports the hostname+SSL fully active, performs a REAL HTTP
     * request to the tenant's own candidate domain (routes/web.php's
     * __custom-domain-check route) to prove this app's ORIGIN actually
     * serves that tenant's storefront — see CloudflareDomainService's
     * docblock for exactly why that second check is required on this
     * specific Hostinger shared-hosting environment (confirmed by direct
     * testing: the origin 403s any unregistered Host header, independent
     * of Cloudflare). Only when BOTH checks pass does this ever set
     * custom_domain/custom_domain_verified — never a false "Active".
     */
    public function refreshDomainConnection(Tenant $tenant, CloudflareDomainService $cloudflare)
    {
        if (! Tenant::cloudflareDomainColumnsReady() || ! $tenant->cf_custom_hostname_id) {
            return back()->with('error', 'কোনো Cloudflare কানেকশন শুরু করা হয়নি — আগে Connect করুন।');
        }

        $result = $cloudflare->getCustomHostnameStatus($tenant->cf_custom_hostname_id);

        if (! $result->successful) {
            $tenant->update(['custom_domain_connect_status' => 'failed', 'custom_domain_connect_error' => $result->errorMessage]);

            return back()->with('error', 'Cloudflare স্ট্যাটাস চেক ব্যর্থ হয়েছে: '.$result->errorMessage);
        }

        if (! $result->active) {
            $tenant->update(['custom_domain_connect_status' => 'connecting']);

            return back()->with('success', 'এখনো প্রস্তুত হচ্ছে — Cloudflare স্ট্যাটাস: '.($result->cfStatus ?? '—').' / SSL: '.($result->sslStatus ?? '—'));
        }

        // Cloudflare's edge is ready — now prove OUR origin actually
        // serves this exact domain (the origin-vhost gap this hosting
        // plan has — see CloudflareDomainService's docblock). Never
        // trust Cloudflare's status alone for something this consequential.
        try {
            $check = Http::timeout(10)->get('https://'.$tenant->custom_domain_requested.'/__custom-domain-check');
            $verified = $check->successful() && (int) $check->json('tenant_id') === $tenant->id;
        } catch (\Throwable $e) {
            $verified = false;
        }

        if (! $verified) {
            $tenant->update(['custom_domain_connect_status' => 'connected']);

            return back()->with('error', 'Cloudflare-এ DNS/SSL রেডি, কিন্তু আমাদের সার্ভার এখনো এই ডোমেইনের জন্য সাড়া দিচ্ছে না — hPanel-এ ডোমেইনটি Parked/Addon Domain হিসেবে (শপসাস-এর একই public ফোল্ডার দেখিয়ে) যোগ করুন, তারপর আবার "স্ট্যাটাস রিফ্রেশ করুন"।');
        }

        try {
            $tenant->update([
                'custom_domain' => $tenant->custom_domain_requested,
                'custom_domain_verified' => 1,
                'custom_domain_connect_status' => 'connected',
                'custom_domain_connect_error' => null,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return back()->with('error', 'এই ডোমেইন ইতিমধ্যে অন্য একটি স্টোরে সক্রিয় আছে।');
            }

            throw $e;
        }

        return back()->with('success', $tenant->custom_domain.' এখন সম্পূর্ণ যাচাইকৃত ও সক্রিয় (Active)।');
    }

    /**
     * Step 2 of 2 (manual fallback path — used when Cloudflare isn't
     * configured, or an admin still wants to finish DNS/SSL by hand) —
     * staff clicks this after manually finishing DNS/SSL
     * setup outside this app (adding the domain as a cPanel addon domain,
     * pointing DNS, issuing SSL — see the module's manual-hosting-
     * workflow docs). This is the ONE place custom_domain/
     * custom_domain_verified (what ResolveCustomDomain middleware
     * actually routes on) get set — never falsely marked verified/active
     * automatically. Idempotent: calling it again on an already-active
     * domain (e.g. after a deactivate/reactivate cycle) is a safe no-op
     * beyond re-confirming the same values.
     */
    public function activateDomain(Tenant $tenant)
    {
        if (! $tenant->custom_domain_requested) {
            return back()->with('error', 'কোনো ডোমেইন রিকোয়েস্ট নেই।');
        }

        if (! in_array($tenant->custom_domain_request_status, ['approved', 'dns_verified'], true) && ! $tenant->custom_domain_verified) {
            return back()->with('error', 'আগে রিকোয়েস্টটি Approve করুন।');
        }

        try {
            $tenant->update([
                'custom_domain' => $tenant->custom_domain_requested,
                'custom_domain_verified' => 1,
                'custom_domain_request_status' => 'approved',
            ]);
        } catch (QueryException $e) {
            // tenants.custom_domain has a UNIQUE constraint (database/sql/
            // chunk20.sql) — same "turn the integrity-violation exception
            // into a normal message" pattern SettingController::
            // messenger() already applies to page_id.
            if ($e->getCode() === '23000') {
                return back()->with('error', 'এই ডোমেইন ইতিমধ্যে অন্য একটি স্টোরে সক্রিয় আছে।');
            }

            throw $e;
        }

        return back()->with('success', $tenant->custom_domain.' এখন সক্রিয় (Active)।');
    }

    /**
     * Turns off routing for an active custom domain WITHOUT clearing the
     * mapping (custom_domain/custom_domain_requested stay put, status
     * stays 'approved') — App\Http\Middleware\ResolveCustomDomain only
     * ever matches custom_domain_verified=true, so this alone stops the
     * domain from resolving. Re-activating later is just calling
     * activateDomain() again, no re-request needed.
     */
    public function deactivateDomain(Tenant $tenant)
    {
        if (! $tenant->custom_domain_verified) {
            return back()->with('error', 'এই ডোমেইন বর্তমানে সক্রিয় নয়।');
        }

        $tenant->update(['custom_domain_verified' => 0]);

        return back()->with('success', $tenant->custom_domain.' নিষ্ক্রিয় করা হয়েছে।');
    }

    /** Full reset — clears the mapping entirely so the tenant can submit a fresh request from scratch. */
    public function destroyDomain(Tenant $tenant, CloudflareDomainService $cloudflare)
    {
        // Best-effort — never let a Cloudflare API failure block clearing
        // the LOCAL mapping (the authoritative record). See
        // CloudflareDomainService::deleteCustomHostname()'s docblock.
        if (Tenant::cloudflareDomainColumnsReady() && $tenant->cf_custom_hostname_id) {
            $cloudflare->deleteCustomHostname($tenant->cf_custom_hostname_id);
        }

        $update = [
            'custom_domain' => null,
            'custom_domain_verified' => 0,
            'custom_domain_requested' => null,
            'custom_domain_request_status' => 'none',
            'custom_domain_verification_token' => null,
            'custom_domain_dns_verified_at' => null,
        ];

        if (Tenant::cloudflareDomainColumnsReady()) {
            $update['custom_domain_connect_status'] = 'not_connected';
            $update['cf_custom_hostname_id'] = null;
            $update['custom_domain_connect_error'] = null;
        }

        $tenant->update($update);

        return back()->with('success', 'কাস্টম ডোমেইন সম্পূর্ণ মুছে ফেলা হয়েছে।');
    }

    /**
     * Blocked once the domain is genuinely live — an active mapping must
     * go through deactivateDomain()/destroyDomain() instead of silently
     * rejecting a request that's already serving real traffic.
     */
    public function rejectDomain(Tenant $tenant)
    {
        if ($tenant->custom_domain_verified) {
            return back()->with('error', 'সক্রিয় ডোমেইন প্রত্যাখ্যান করা যাবে না — আগে Deactivate করুন।');
        }

        $tenant->update(['custom_domain_request_status' => 'rejected']);

        return back()->with('success', 'ডোমেইন রিকোয়েস্ট প্রত্যাখ্যান করা হয়েছে।');
    }

    /** Manual extension — e.g. cash/bKash-personal payment taken outside the gateway.
     *  Admin picks the exact end date from a calendar instead of a day-count. */
    public function extend(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'subscription_ends_at' => 'required|date|after:today',
            'plan_id' => 'required|exists:plans,id',
        ]);

        $tenant->update([
            'status' => 'active',
            'plan_id' => $data['plan_id'],
            'subscription_ends_at' => Carbon::parse($data['subscription_ends_at'])->endOfDay(),
        ]);

        SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'gateway' => 'manual',
            'amount' => 0,
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => ['note' => 'Manual extend to '.$data['subscription_ends_at'].' by super admin'],
        ]);

        return back()->with('success', 'সাবস্ক্রিপশন '.Carbon::parse($data['subscription_ends_at'])->format('d M Y').' পর্যন্ত আপডেট হয়েছে।');
    }
}
