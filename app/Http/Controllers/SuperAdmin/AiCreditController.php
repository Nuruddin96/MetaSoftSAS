<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AI\AiCreditService;
use Illuminate\Http\Request;

/**
 * Full AI credit visibility/control — the only place estimated_cost_usd,
 * input/output token counts, and model-per-call are ever displayed. Every
 * mutation (allocate, adjust) goes through AiCreditService, same as the
 * tenant Settings page — this controller never touches
 * ai_credit_accounts.balance or ai_usage_ledger directly.
 */
class AiCreditController extends Controller
{
    public function __construct(protected AiCreditService $service) {}

    public function index(Request $request)
    {
        $tenants = Tenant::with(['aiCreditAccount'])
            ->when($request->q, fn ($q) => $q->where('store_name', 'like', '%'.$request->q.'%'))
            ->when($request->status === 'exhausted', fn ($q) => $q->where(fn ($qq) => $qq
                ->whereDoesntHave('aiCreditAccount')
                ->orWhereHas('aiCreditAccount', fn ($a) => $a->where('balance', '<=', 0))))
            ->when($request->status === 'has_credit', fn ($q) => $q->whereHas('aiCreditAccount', fn ($a) => $a->where('balance', '>', 0)))
            ->orderBy('store_name')
            ->paginate(25)->withQueryString();

        return view('super.ai-credit.index', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant)
    {
        $account = $this->service->getAccount($tenant->id);

        return view('super.ai-credit.show', [
            'tenant' => $tenant,
            'account' => $account,
            'ledger' => $this->service->ledger($tenant->id, perPage: 30),
        ]);
    }

    public function allocate(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $this->service->allocate($tenant->id, (float) $data['amount'], $data['note'] ?? null, auth('super_admin')->id());

        return back()->with('success', 'AI ক্রেডিট বরাদ্দ করা হয়েছে।');
    }

    public function adjust(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'required|string|max:255',
        ]);

        $this->service->adjust(
            $tenant->id,
            (float) $data['amount'],
            $data['direction'],
            $data['note'],
            auth('super_admin')->id(),
        );

        return back()->with('success', 'অ্যাডজাস্টমেন্ট এন্ট্রি যোগ হয়েছে।');
    }
}
