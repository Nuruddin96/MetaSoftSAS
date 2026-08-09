<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\ServiceLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AffiliateController extends Controller
{
    public function index(Request $request)
    {
        $affiliates = Affiliate::withCount(['referredTenants', 'serviceLeads'])
            ->withSum(['commissions as pending_sum' => fn ($q) => $q->where('status', 'pending')], 'amount')
            ->when($request->q, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('email', 'like', '%'.$request->q.'%')
                ->orWhere('referral_code', 'like', '%'.$request->q.'%')))
            ->latest()->paginate(25)->withQueryString();

        return view('super.affiliates.index', compact('affiliates'));
    }

    public function show(Affiliate $affiliate)
    {
        return view('super.affiliates.show', [
            'affiliate' => $affiliate,
            'tenants' => $affiliate->referredTenants()->with('plan')->get(),
            'commissions' => $affiliate->commissions()->latest()->get(),
            'leads' => $affiliate->serviceLeads()->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('super.affiliates.form', ['affiliate' => null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:affiliates,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|min:6',
            'referral_code' => 'nullable|string|max:20|unique:affiliates,referral_code',
            'payment_method' => 'nullable|string|max:30',
            'payment_number' => 'nullable|string|max:30',
            'status' => 'required|in:active,suspended',
        ]);

        $data['password'] = Hash::make($data['password']);
        if (! empty($data['referral_code'])) {
            $data['referral_code'] = strtoupper($data['referral_code']);
        }

        $affiliate = Affiliate::create($data);

        return redirect()->route('super.affiliates.show', $affiliate)->with('success', 'অ্যাফিলিয়েট যোগ হয়েছে।');
    }

    public function edit(Affiliate $affiliate)
    {
        return view('super.affiliates.form', compact('affiliate'));
    }

    public function updateInfo(Request $request, Affiliate $affiliate)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:affiliates,email,'.$affiliate->id,
            'phone' => 'required|string|max:20',
            'password' => 'nullable|min:6',
            'referral_code' => 'required|string|max:20|unique:affiliates,referral_code,'.$affiliate->id,
            'payment_method' => 'nullable|string|max:30',
            'payment_number' => 'nullable|string|max:30',
            'status' => 'required|in:active,suspended',
        ]);

        $data['referral_code'] = strtoupper($data['referral_code']);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $affiliate->update($data);

        return redirect()->route('super.affiliates.show', $affiliate)->with('success', 'অ্যাফিলিয়েটের তথ্য আপডেট হয়েছে।');
    }

    public function destroy(Affiliate $affiliate)
    {
        $affiliate->delete();

        return redirect()->route('super.affiliates')->with('success', 'অ্যাফিলিয়েট মুছে ফেলা হয়েছে।');
    }

    public function suspend(Affiliate $affiliate)
    {
        $affiliate->update(['status' => $affiliate->status === 'active' ? 'suspended' : 'active']);

        return back()->with('success', 'স্ট্যাটাস বদলানো হয়েছে।');
    }

    public function markPaid(AffiliateCommission $commission)
    {
        $commission->update(['status' => 'paid']);

        return back()->with('success', 'কমিশন পরিশোধিত হিসেবে চিহ্নিত হয়েছে।');
    }

    /** Add this month's ৳1000 recurring commission for an active/converted service referral. */
    public function addServiceCommission(Request $request, ServiceLead $lead)
    {
        AffiliateCommission::create([
            'affiliate_id' => $lead->affiliate_id,
            'type' => 'service',
            'source_label' => $lead->client_name.' ('.($lead->package ?: 'সার্ভিস প্যাকেজ').')',
            'service_lead_id' => $lead->id,
            'amount' => 1000,
            'is_recurring' => true,
            'status' => 'pending',
            'note' => now()->format('F Y').' মাসের রেফারেল কমিশন',
        ]);

        return back()->with('success', '১০০০৳ কমিশন যোগ হয়েছে।');
    }

    public function updateLead(Request $request, ServiceLead $lead)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,lost']);
        $lead->update($data);

        return back()->with('success', 'লিডের স্ট্যাটাস আপডেট হয়েছে।');
    }
}
