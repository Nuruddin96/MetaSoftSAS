@extends('layouts.super')
@section('title', $tenant->store_name.' — অ্যাডভার্টাইজিং')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $tenant->store_name }}</h1>
        <p class="text-sm text-mute">{{ $tenant->owner_name }} · {{ $tenant->owner_phone }} · প্ল্যান: {{ $tenant->plan?->name }}</p>
    </div>
    <a href="{{ route('super.advertising.index') }}" class="text-sm text-mute hover:text-ink">← সব টেনেন্ট</a>
</div>

@if (! $tenant->plan?->allow_meta_ads)
    <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 p-4 text-sm mb-6">
        এই টেনেন্টের প্ল্যানে "Meta Ads" ফিচারটি বন্ধ আছে — মডিউল চালু থাকলেও টেনেন্ট সাইডবার/হেডারে কিছু দেখাবে না, যতক্ষণ না প্ল্যানে এটি চালু করা হয়।
        <a href="{{ route('super.plans') }}" class="font-semibold underline">প্ল্যান সেটিংস</a>
    </div>
@endif

@if (! $account)
    {{-- Not activated yet — this form is the module-activation switch --}}
    <div class="bg-white rounded-xl border border-ink/5 p-6 max-w-md">
        <p class="font-bold text-sm mb-4">🔌 অ্যাডভার্টাইজিং মডিউল চালু করুন</p>
        <form method="POST" action="{{ route('super.advertising.activate', $tenant) }}" class="space-y-3">
            @csrf
            <div>
                <label class="text-xs text-mute">দৈনিক বাজেট (৳)</label>
                <input name="daily_budget" type="number" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="text-xs text-mute">বিলিং রেট (৳ প্রতি USD)</label>
                <input name="billing_rate" type="number" step="0.0001" min="0" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="text-xs text-mute">লো-ব্যালেন্স থ্রেশহোল্ড (৳, ঐচ্ছিক)</label>
                <input name="low_balance_threshold" type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
            </div>
            <button class="w-full py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">মডিউল চালু করুন</button>
        </form>
    </div>
@else
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">টেনেন্ট-ফেসিং ব্যালেন্স</p><p class="font-disp font-extrabold text-xl mt-1 {{ $account->balance <= 0 ? 'text-red-600' : '' }}">৳{{ number_format($account->balance, 2) }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">দৈনিক বাজেট</p><p class="font-disp font-extrabold text-xl mt-1">৳{{ number_format($account->daily_budget, 2) }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">বিলিং রেট</p><p class="font-disp font-extrabold text-xl mt-1">৳{{ number_format($account->billing_rate, 2) }}/USD</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মডিউল স্ট্যাটাস</p><p class="font-disp font-extrabold text-xl mt-1">{{ $account->is_active ? '✅ চালু' : '⛔ বন্ধ' }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Full ledger — internal-only columns (Meta spend/margin) shown here, never in the tenant module --}}
            <div class="bg-white rounded-xl border border-ink/5 overflow-hidden">
                <p class="font-bold text-sm px-5 py-4 border-b border-ink/5">লেজার (ইন্টারনাল ভিউ)</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-paper/60 text-mute text-xs uppercase">
                            <tr>
                                <th class="px-4 py-3 text-left">তারিখ</th>
                                <th class="px-4 py-3 text-left">ধরন</th>
                                <th class="px-4 py-3 text-left">নোট</th>
                                <th class="px-4 py-3 text-right">Meta Spend (USD)</th>
                                <th class="px-4 py-3 text-right">পরিমাণ (৳)</th>
                                <th class="px-4 py-3 text-right">ব্যালেন্স</th>
                                <th class="px-4 py-3 text-left">এডমিন</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink/5">
                            @forelse ($ledger as $entry)
                                @php $credit = in_array($entry->type, ['payment', 'adjustment_credit']); @endphp
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $entry->created_at->timezone(config('advertising.timezone'))->format('d M Y, h:i A') }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $entry->type }}</td>
                                    <td class="px-4 py-3 text-mute text-xs">{{ $entry->note ?: '—' }}</td>
                                    <td class="px-4 py-3 text-right text-mute">{{ $entry->meta_spend_usd ? '$'.number_format($entry->meta_spend_usd, 2) : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-semibold {{ $credit ? 'text-leafdk' : 'text-red-600' }}">{{ $credit ? '+' : '−' }}৳{{ number_format($entry->amount, 2) }}</td>
                                    <td class="px-4 py-3 text-right text-mute">৳{{ number_format($entry->balance_after, 2) }}</td>
                                    <td class="px-4 py-3 text-xs text-mute">{{ $entry->admin?->name ?? ($entry->created_by ? '—' : 'সিস্টেম (দৈনিক চার্জ)') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-10 text-center text-mute">কোনো এন্ট্রি নেই।</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>{{ $ledger->links() }}</div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-ink/5 p-5">
                <p class="font-bold text-sm mb-3">⚙️ সেটিংস</p>
                <form method="POST" action="{{ route('super.advertising.settings', $tenant) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <label class="text-xs text-mute">দৈনিক বাজেট (৳)</label>
                        <input name="daily_budget" type="number" step="0.01" min="0" value="{{ $account->daily_budget }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-mute">বিলিং রেট (৳/USD)</label>
                        <input name="billing_rate" type="number" step="0.0001" min="0" value="{{ $account->billing_rate }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-mute">লো-ব্যালেন্স থ্রেশহোল্ড (৳)</label>
                        <input name="low_balance_threshold" type="number" step="0.01" min="0" value="{{ $account->low_balance_threshold }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)> মডিউল চালু</label>
                    <button class="w-full py-2.5 rounded-lg bg-ink text-white font-semibold text-sm hover:bg-ink/90">সেভ করুন</button>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-ink/5 p-5">
                <p class="font-bold text-sm mb-3">💳 পেমেন্ট যোগ করুন</p>
                <form method="POST" action="{{ route('super.advertising.payments.store', $tenant) }}" class="space-y-3">
                    @csrf
                    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="পরিমাণ (৳)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="note" placeholder="নোট (ঐচ্ছিক)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <button class="w-full py-2 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">পেমেন্ট যোগ করুন</button>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-ink/5 p-5">
                <p class="font-bold text-sm mb-3">🧾 চার্জ যোগ করুন (Meta Spend থেকে)</p>
                <form method="POST" action="{{ route('super.advertising.charges.store', $tenant) }}" class="space-y-3">
                    @csrf
                    <input name="meta_spend_usd" type="number" step="0.01" min="0.01" required placeholder="Meta Spend ($)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <p class="text-xs text-mute">রেট ৳{{ number_format($account->billing_rate, 2) }}/USD অনুযায়ী স্বয়ংক্রিয়ভাবে ৳-এ চার্জ হবে।</p>
                    <input name="note" placeholder="নোট (ঐচ্ছিক)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <button class="w-full py-2 rounded-lg bg-red-600 text-white font-semibold text-sm hover:bg-red-700">চার্জ যোগ করুন</button>
                </form>
            </div>

            <div class="bg-white rounded-xl border border-ink/5 p-5">
                <p class="font-bold text-sm mb-3">🛠️ ম্যানুয়াল অ্যাডজাস্টমেন্ট</p>
                <form method="POST" action="{{ route('super.advertising.adjustments.store', $tenant) }}" class="space-y-3">
                    @csrf
                    <select name="direction" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                        <option value="credit">ক্রেডিট (ব্যালেন্স বাড়বে)</option>
                        <option value="debit">ডেবিট (ব্যালেন্স কমবে)</option>
                    </select>
                    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="পরিমাণ (৳)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="note" required placeholder="কারণ (আবশ্যক)" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <button class="w-full py-2 rounded-lg bg-amber text-ink font-semibold text-sm hover:brightness-95">অ্যাডজাস্টমেন্ট যোগ করুন</button>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
