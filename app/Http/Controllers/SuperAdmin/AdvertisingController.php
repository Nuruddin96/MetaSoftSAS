<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Advertising\AdvertisingBalanceService;
use Illuminate\Http\Request;

/**
 * Full Advertising Billing visibility/control — the only place
 * meta_spend_usd, internal cost, and margin are ever computed or
 * displayed. Every mutation (activate, settings, payment, charge,
 * adjustment) goes through AdvertisingBalanceService, same as the tenant
 * module — this controller never touches ad_billing_accounts.balance or
 * ad_billing_ledger directly.
 */
class AdvertisingController extends Controller
{
    public function __construct(protected AdvertisingBalanceService $service) {}

    public function index(Request $request)
    {
        $tenants = Tenant::with(['adBillingAccount', 'plan'])
            ->when($request->q, fn ($q) => $q->where('store_name', 'like', '%'.$request->q.'%'))
            ->when($request->status === 'active', fn ($q) => $q->whereHas('adBillingAccount', fn ($a) => $a->where('is_active', 1)))
            ->when($request->status === 'inactive', fn ($q) => $q->where(fn ($qq) => $qq
                ->whereDoesntHave('adBillingAccount')
                ->orWhereHas('adBillingAccount', fn ($a) => $a->where('is_active', 0))))
            ->orderBy('store_name')
            ->paginate(25)->withQueryString();

        return view('super.advertising.index', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant)
    {
        $account = $this->service->getAccount($tenant->id);

        return view('super.advertising.show', [
            'tenant' => $tenant,
            'account' => $account,
            'ledger' => $account ? $this->service->ledger($tenant->id, perPage: 30) : null,
        ]);
    }

    /** Switches the module on for a tenant that doesn't have an account yet. */
    public function activate(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'daily_budget' => 'required|numeric|min:0',
            'billing_rate' => 'required|numeric|min:0',
            'low_balance_threshold' => 'nullable|numeric|min:0',
        ]);

        $this->service->activate(
            $tenant->id,
            (float) $data['daily_budget'],
            (float) $data['billing_rate'],
            (float) ($data['low_balance_threshold'] ?? 0)
        );

        return redirect()->route('super.advertising.show', $tenant)->with('success', 'অ্যাডভার্টাইজিং মডিউল চালু করা হয়েছে।');
    }

    public function updateSettings(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'daily_budget' => 'required|numeric|min:0',
            'billing_rate' => 'required|numeric|min:0',
            'low_balance_threshold' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['low_balance_threshold'] = $data['low_balance_threshold'] ?? 0;

        $this->service->updateSettings($tenant->id, $data);

        return back()->with('success', 'সেটিংস আপডেট হয়েছে।');
    }

    public function storePayment(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $this->service->recordPayment($tenant->id, (float) $data['amount'], $data['note'] ?? null, auth('super_admin')->id());

        return back()->with('success', 'পেমেন্ট এন্ট্রি যোগ হয়েছে।');
    }

    public function storeCharge(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'meta_spend_usd' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $account = $this->service->getAccount($tenant->id);
        abort_unless($account, 404);

        $amountBdt = round((float) $data['meta_spend_usd'] * (float) $account->billing_rate, 2);

        $this->service->recordCharge(
            $tenant->id,
            $amountBdt,
            (float) $data['meta_spend_usd'],
            $data['note'] ?? 'ম্যানুয়াল চার্জ',
            adminId: auth('super_admin')->id(),
        );

        return back()->with('success', 'চার্জ এন্ট্রি যোগ হয়েছে।');
    }

    public function storeAdjustment(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'required|string|max:255',
        ]);

        $this->service->recordAdjustment(
            $tenant->id,
            (float) $data['amount'],
            $data['direction'],
            $data['note'],
            auth('super_admin')->id(),
        );

        return back()->with('success', 'অ্যাডজাস্টমেন্ট এন্ট্রি যোগ হয়েছে।');
    }
}
