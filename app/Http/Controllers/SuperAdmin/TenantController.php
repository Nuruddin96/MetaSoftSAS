<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Domain\DomainManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $tenants = Tenant::with('plan')
            ->when($request->q, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('store_name', 'like', '%' . $request->q . '%')
                ->orWhere('subdomain', 'like', '%' . $request->q . '%')
                ->orWhere('owner_phone', 'like', '%' . $request->q . '%')
                ->orWhere('owner_email', 'like', '%' . $request->q . '%')))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()->paginate(25)->withQueryString();

        return view('super.tenants', compact('tenants'));
    }

    public function show(Tenant $tenant)
    {
        return view('super.tenant-show', [
            'tenant'   => $tenant->load('plan'),
            'plans'    => Plan::orderBy('sort_order')->get(),
            'orders'   => Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'staff'    => User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'payments' => SubscriptionPayment::where('tenant_id', $tenant->id)->latest()->limit(10)->get(),
            'domainActivationInstructions' => $tenant->custom_domain_request_status === 'dns_verified'
                ? DomainManager::driver()->activationInstructions($tenant)
                : null,
        ]);
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);
        return back()->with('success', $tenant->store_name . ' সাসপেন্ড করা হয়েছে।');
    }

    public function activate(Tenant $tenant)
    {
        $status = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
            ? 'active'
            : (($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) ? 'trial' : 'expired');

        $tenant->update(['status' => $status]);
        return back()->with('success', $tenant->store_name . ' অ্যাক্টিভেট করা হয়েছে (' . $status . ')।');
    }

    public function edit(Tenant $tenant)
    {
        return view('super.tenant-edit', ['tenant' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'store_name'  => 'required|string|max:150',
            'subdomain'   => 'required|string|max:63|regex:/^[a-z0-9-]+$/|unique:tenants,subdomain,' . $tenant->id,
            'owner_name'  => 'required|string|max:150',
            'owner_phone' => 'required|string|max:20',
            'owner_email' => 'required|email|unique:tenants,owner_email,' . $tenant->id,
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

        return back()->with('success', $owner->email . ' — এর পাসওয়ার্ড পরিবর্তন করা হয়েছে।');
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();

        return redirect()->route('super.tenants')->with('success', 'টেনেন্ট ও তার সব ডেটা মুছে ফেলা হয়েছে।');
    }

    /** Staff-triggered DNS TXT check — replaces the old "just trust the typed domain" approval. */
    public function verifyDomainDns(Tenant $tenant)
    {
        if (! $tenant->custom_domain_requested) {
            return back()->with('error', 'কোনো ডোমেইন রিকোয়েস্ট নেই।');
        }

        if (! DomainManager::driver()->verifyDns($tenant)) {
            return back()->with('error', 'TXT রেকর্ড এখনো পাওয়া যায়নি — DNS propagate হতে সময় লাগতে পারে (২৪-৪৮ ঘণ্টা পর্যন্ত), কিছুক্ষণ পর আবার চেষ্টা করুন।');
        }

        $tenant->update([
            'custom_domain_request_status'  => 'dns_verified',
            'custom_domain_dns_verified_at' => now(),
        ]);

        return back()->with('success', 'DNS TXT রেকর্ড যাচাই সফল হয়েছে — এখন ম্যানুয়াল সেটআপ শেষ করে Activate করুন।');
    }

    /** Staff clicks this after manually adding the domain as a cPanel addon domain. */
    public function approveDomain(Tenant $tenant)
    {
        if ($tenant->custom_domain_request_status !== 'dns_verified') {
            return back()->with('error', 'আগে DNS TXT রেকর্ড যাচাই করুন।');
        }

        $tenant->update([
            'custom_domain'                 => $tenant->custom_domain_requested,
            'custom_domain_verified'        => 1,
            'custom_domain_request_status'  => 'approved',
        ]);

        return back()->with('success', $tenant->custom_domain . ' চালু করা হয়েছে।');
    }

    public function rejectDomain(Tenant $tenant)
    {
        $tenant->update(['custom_domain_request_status' => 'rejected']);

        return back()->with('success', 'ডোমেইন রিকোয়েস্ট বাতিল করা হয়েছে।');
    }

    /** Manual extension — e.g. cash/bKash-personal payment taken outside the gateway.
     *  Admin picks the exact end date from a calendar instead of a day-count. */
    public function extend(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'subscription_ends_at' => 'required|date|after:today',
            'plan_id'              => 'required|exists:plans,id',
        ]);

        $tenant->update([
            'status'               => 'active',
            'plan_id'              => $data['plan_id'],
            'subscription_ends_at' => \Carbon\Carbon::parse($data['subscription_ends_at'])->endOfDay(),
        ]);

        SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'gateway'   => 'manual',
            'amount'    => 0,
            'status'    => 'completed',
            'paid_at'   => now(),
            'gateway_response' => ['note' => 'Manual extend to ' . $data['subscription_ends_at'] . ' by super admin'],
        ]);

        return back()->with('success', 'সাবস্ক্রিপশন ' . \Carbon\Carbon::parse($data['subscription_ends_at'])->format('d M Y') . ' পর্যন্ত আপডেট হয়েছে।');
    }
}
