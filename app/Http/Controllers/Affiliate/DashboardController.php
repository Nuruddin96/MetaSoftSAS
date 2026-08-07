<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\ServiceLead;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $affiliate = auth('affiliate')->user();

        return view('affiliate.dashboard', [
            'affiliate'      => $affiliate,
            'referredTenants'=> $affiliate->referredTenants()->latest()->limit(10)->get(),
            'commissions'    => $affiliate->commissions()->latest()->limit(15)->get(),
            'pendingTotal'   => (float) $affiliate->commissions()->where('status', 'pending')->sum('amount'),
            'paidTotal'      => (float) $affiliate->commissions()->where('status', 'paid')->sum('amount'),
            'serviceLeads'   => $affiliate->serviceLeads()->latest()->limit(10)->get(),
        ]);
    }

    public function submitLead(Request $request)
    {
        $data = $request->validate([
            'client_name'  => 'required|string|max:150',
            'client_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'package'      => 'nullable|string|max:100',
            'note'         => 'nullable|string|max:500',
        ], [
            'client_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        $data['affiliate_id'] = auth('affiliate')->id();
        $data['status'] = 'new';

        ServiceLead::create($data);

        return back()->with('success', 'ক্লায়েন্টের তথ্য পাঠানো হয়েছে — আমরা যোগাযোগ করবো।');
    }
}
