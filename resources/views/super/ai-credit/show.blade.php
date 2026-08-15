@extends('layouts.super')
@section('title', $tenant->store_name.' — AI ক্রেডিট')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $tenant->store_name }}</h1>
        <p class="text-sm text-mute">{{ $tenant->owner_name }} · {{ $tenant->owner_phone }} · প্ল্যান: {{ $tenant->plan?->name }}</p>
    </div>
    <a href="{{ route('super.ai-credit.index') }}" class="text-sm text-mute hover:text-ink">← সব টেনেন্ট</a>
</div>

<div class="grid grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">বর্তমান ব্যালেন্স</p>
        <p class="font-disp font-extrabold text-xl mt-1 {{ ! $account || $account->balance <= 0 ? 'text-red-600' : '' }}">
            {{ $account ? number_format($account->balance, 2) : '০ (বরাদ্দ হয়নি)' }}
        </p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">AI Agent স্ট্যাটাস (এই ব্যালেন্সের ভিত্তিতে)</p>
        <p class="font-disp font-extrabold text-xl mt-1">
            {{ $account && $account->balance > 0 ? '✅ কল করতে পারবে' : '⛔ CREDIT_EXHAUSTED' }}
        </p>
    </div>
</div>

@if ($account && $account->balance <= 0)
    <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-4 text-sm mb-6">
        এই টেনেন্টের ক্রেডিট শেষ। তাদের AI এজেন্ট/Messenger অটো-রিপ্লাই সেটিংস অপরিবর্তিত আছে — শুধু নতুন ক্রেডিট বরাদ্দ না করা পর্যন্ত কোনো OpenAI কল যাবে না।
    </div>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl border border-ink/5 overflow-hidden">
            <p class="font-bold text-sm px-5 py-4 border-b border-ink/5">লেজার (ইন্টারনাল ভিউ — টোকেন/কস্ট সহ)</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-paper/60 text-mute text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">তারিখ</th>
                            <th class="px-4 py-3 text-left">ধরন</th>
                            <th class="px-4 py-3 text-left">নোট / কনটেক্সট</th>
                            <th class="px-4 py-3 text-right">টোকেন (in/out)</th>
                            <th class="px-4 py-3 text-right">মডেল</th>
                            <th class="px-4 py-3 text-right">আনুমানিক কস্ট (USD)</th>
                            <th class="px-4 py-3 text-right">পরিমাণ</th>
                            <th class="px-4 py-3 text-right">ব্যালেন্স</th>
                            <th class="px-4 py-3 text-left">এডমিন</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink/5">
                        @forelse ($ledger as $entry)
                            @php $credit = in_array($entry->type, ['allocation', 'adjustment_credit']); @endphp
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $entry->created_at->format('d M Y, h:i A') }}</td>
                                <td class="px-4 py-3 text-xs">{{ $entry->type }}</td>
                                <td class="px-4 py-3 text-mute text-xs">
                                    {{ $entry->note ?: '—' }}
                                    @if ($entry->context_type)
                                        <span class="block text-[11px] text-mute/70">{{ $entry->context_type }}@if($entry->context_id) #{{ $entry->context_id }}@endif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-mute">{{ $entry->input_tokens !== null ? $entry->input_tokens.'/'.$entry->output_tokens : '—' }}</td>
                                <td class="px-4 py-3 text-right text-mute text-xs">{{ $entry->model ?: '—' }}</td>
                                <td class="px-4 py-3 text-right text-mute">{{ $entry->estimated_cost_usd ? '$'.number_format($entry->estimated_cost_usd, 6) : '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold {{ $credit ? 'text-leafdk' : 'text-red-600' }}">{{ $credit ? '+' : '−' }}{{ number_format($entry->credit_amount, 4) }}</td>
                                <td class="px-4 py-3 text-right text-mute">{{ number_format($entry->balance_after, 4) }}</td>
                                <td class="px-4 py-3 text-xs text-mute">{{ $entry->admin?->name ?? ($entry->created_by ? '—' : 'সিস্টেম (AI ব্যবহার)') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-mute">কোনো এন্ট্রি নেই।</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($ledger)
            <div>{{ $ledger->links() }}</div>
        @endif
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <p class="font-bold text-sm mb-3">💳 ক্রেডিট বরাদ্দ করুন</p>
            <form method="POST" action="{{ route('super.ai-credit.allocate', $tenant) }}" class="space-y-3">
                @csrf
                <input name="amount" type="number" step="0.01" min="0.01" required placeholder="পরিমাণ" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                <input name="note" placeholder="নোট (ঐচ্ছিক)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                <button class="w-full py-2 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">ক্রেডিট যোগ করুন</button>
            </form>
        </div>

        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <p class="font-bold text-sm mb-3">🛠️ ম্যানুয়াল অ্যাডজাস্টমেন্ট</p>
            <form method="POST" action="{{ route('super.ai-credit.adjustments.store', $tenant) }}" class="space-y-3">
                @csrf
                <select name="direction" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                    <option value="credit">ক্রেডিট (ব্যালেন্স বাড়বে)</option>
                    <option value="debit">ডেবিট (ব্যালেন্স কমবে)</option>
                </select>
                <input name="amount" type="number" step="0.01" min="0.01" required placeholder="পরিমাণ" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                <input name="note" required placeholder="কারণ (আবশ্যক)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                <button class="w-full py-2 rounded-lg bg-amber text-ink font-semibold text-sm hover:brightness-95">অ্যাডজাস্টমেন্ট যোগ করুন</button>
            </form>
        </div>
    </div>
</div>
@endsection
