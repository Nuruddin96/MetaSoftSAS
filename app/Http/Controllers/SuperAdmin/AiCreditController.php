<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
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
            ->when($request->status === 'paused' && Tenant::aiPauseColumnsReady(), fn ($q) => $q->whereNotNull('ai_paused_at'))
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
            // Phase 14 — the tenant's OWN toggles, read the same
            // "no row = disabled" way ProcessAiAgentMessage/
            // ProcessWhatsAppAiAgentMessage already do, purely for
            // display here (never written from this controller).
            'toggles' => $this->readToggles($tenant->id),
        ]);
    }

    /** @return array{ai_agent_enabled: bool, messenger_ai_auto_reply_enabled: bool, whatsapp_ai_auto_reply_enabled: bool} */
    protected function readToggles(int $tenantId): array
    {
        $values = StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', ['ai_agent_enabled', 'messenger_ai_auto_reply_enabled', 'whatsapp_ai_auto_reply_enabled'])
            ->pluck('value', 'key');

        return [
            'ai_agent_enabled' => ($values['ai_agent_enabled'] ?? null) === '1',
            'messenger_ai_auto_reply_enabled' => ($values['messenger_ai_auto_reply_enabled'] ?? null) === '1',
            'whatsapp_ai_auto_reply_enabled' => ($values['whatsapp_ai_auto_reply_enabled'] ?? null) === '1',
        ];
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

    /**
     * Phase 14 — independent of credit, see Tenant::isAiPaused()'s
     * docblock for why this is its own column rather than draining the
     * tenant's balance to force a stop (that would tamper with their
     * legitimate credit ledger for an unrelated reason).
     */
    public function pauseAi(Request $request, Tenant $tenant)
    {
        if (! Tenant::aiPauseColumnsReady()) {
            return back()->with('error', 'database/sql/chunk39.sql এখনো ইমপোর্ট করা হয়নি।');
        }

        $data = $request->validate(['reason' => 'required|string|max:255']);

        $tenant->update([
            'ai_paused_at' => now(),
            'ai_paused_by_super_admin_id' => auth('super_admin')->id(),
            'ai_paused_reason' => $data['reason'],
        ]);

        return back()->with('success', 'এই টেনেন্টের AI Agent প্ল্যাটফর্ম থেকে পজ করা হয়েছে।');
    }

    public function resumeAi(Tenant $tenant)
    {
        if (! Tenant::aiPauseColumnsReady()) {
            return back()->with('error', 'database/sql/chunk39.sql এখনো ইমপোর্ট করা হয়নি।');
        }

        $tenant->update([
            'ai_paused_at' => null,
            'ai_paused_by_super_admin_id' => null,
            'ai_paused_reason' => null,
        ]);

        return back()->with('success', 'এই টেনেন্টের AI Agent আবার চালু করা হয়েছে।');
    }
}
