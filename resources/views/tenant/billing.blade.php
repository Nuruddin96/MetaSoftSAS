@extends('layouts.panel')

@section('title', 'বিলিং')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">সাবস্ক্রিপশন</h1>
<p class="text-sm text-mute mb-6">
    বর্তমান অবস্থা: <b class="text-ink">{{ ['trial' => 'ট্রায়াল', 'active' => 'অ্যাক্টিভ', 'expired' => 'মেয়াদ শেষ', 'suspended' => 'সাসপেন্ডেড'][$tenant->status] ?? $tenant->status }}</b>
    @if ($tenant->status === 'trial' && $tenant->trial_ends_at) — শেষ হবে {{ $tenant->trial_ends_at->format('d M Y') }} @endif
    @if ($tenant->status === 'active' && $tenant->subscription_ends_at) — মেয়াদ {{ $tenant->subscription_ends_at->format('d M Y') }} পর্যন্ত @endif
</p>

@if (session('warning'))
    <p class="mb-4 bg-amber/15 border border-amber/40 rounded-xl p-4 text-sm">{{ session('warning') }}</p>
@endif

@if (! $hasBkash && ! $hasSsl)
    <div class="mb-6 bg-white border border-amber/40 rounded-xl p-5">
        <p class="font-bold text-sm mb-1">💳 অনলাইন পেমেন্ট শীঘ্রই আসছে</p>
        <p class="text-sm text-mute mb-4">আপাতত সরাসরি যোগাযোগ করুন — আমরা পেমেন্ট নিশ্চিত হওয়ার পরপরই আপনার প্ল্যান ম্যানুয়ালি চালু করে দেব।</p>
        <div class="flex flex-wrap gap-3">
            <a href="https://wa.me/{{ $supportWa }}?text={{ urlencode('আমি ' . $tenant->store_name . ' এর জন্য সাবস্ক্রিপশন পেমেন্ট করতে চাই।') }}"
               target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-[#25D366] text-white font-semibold text-sm hover:opacity-90">
                <svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15s-.77.96-.94 1.16c-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.02-1.04 2.48 1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.04 21.5h-.01c-1.75 0-3.47-.47-4.97-1.36l-.36-.21-3.7.97.99-3.61-.23-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 012.89 6.99c0 5.45-4.44 9.89-9.89 9.89M20.52 3.45A11.76 11.76 0 0012.04 0C5.5 0 .17 5.33.17 11.88c0 2.09.55 4.13 1.59 5.93L.07 24l6.33-1.66a11.85 11.85 0 005.64 1.44h.01c6.54 0 11.87-5.33 11.87-11.88 0-3.17-1.24-6.15-3.48-8.4"/></svg>
                WhatsApp-এ যোগাযোগ করুন
            </a>
            <a href="tel:{{ $supportPhone }}"
               class="inline-flex items-center gap-2 px-5 py-3 rounded-xl border border-ink/15 font-semibold text-sm hover:bg-paper">
                📞 কল করুন — {{ $supportPhone }}
            </a>
        </div>
        @if ($manualNote)<p class="text-xs text-mute mt-4">{{ $manualNote }}</p>@endif
    </div>
@endif

<div class="grid md:grid-cols-3 gap-5 max-w-4xl">
    @foreach ($plans as $plan)
        <div class="bg-white rounded-2xl border p-6 flex flex-col {{ $tenant->plan_id === $plan->id ? 'border-leaf ring-2 ring-leaf/20' : 'border-ink/10' }}">
            <div class="flex items-center justify-between">
                <h3 class="font-bold">{{ $plan->name }}</h3>
                @if ($tenant->plan_id === $plan->id)<span class="text-xs bg-leaf/10 text-leafdk px-2 py-0.5 rounded-full font-semibold">বর্তমান</span>@endif
            </div>
            <p class="mt-2"><span class="font-disp font-extrabold text-2xl">{{ number_format($plan->price_monthly) }}৳</span><span class="text-mute text-sm">/মাস</span></p>
            <p class="text-xs text-mute">বাৎসরিক {{ number_format($plan->price_yearly) }}৳</p>

            <ul class="mt-4 space-y-2 text-sm text-mute flex-1">
                <li>✓ {{ $plan->max_products ? number_format($plan->max_products) . 'টি প্রোডাক্ট' : 'আনলিমিটেড প্রোডাক্ট' }}</li>
                <li>✓ {{ $plan->max_staff ? $plan->max_staff . ' জন স্টাফ' : 'আনলিমিটেড স্টাফ' }}</li>
                <li>✓ {{ $plan->max_warehouses ? $plan->max_warehouses . 'টি ওয়্যারহাউজ' : 'আনলিমিটেড ওয়্যারহাউজ' }}</li>
                <li>✓ কুরিয়ার, ফ্রড চেকার</li>
                <li>{{ $plan->allow_pos ? '✓ POS বিক্রি' : '✗ POS নেই' }}</li>
                <li>{{ $plan->allow_custom_domain ? '✓ নিজের ডোমেইন' : '✗ কাস্টম ডোমেইন নেই' }}</li>
            </ul>

            @if ($hasSsl || $hasBkash)
                <div class="mt-5 space-y-3">
                    @foreach ([['monthly', '১ মাস', $plan->price_monthly], ['yearly', '১ বছর', $plan->price_yearly]] as [$cycle, $label, $price])
                        <div class="border border-ink/10 rounded-lg p-3">
                            <p class="text-xs font-semibold mb-2">{{ $label }} — {{ number_format($price) }}৳</p>

                            @if ($hasSsl)
                                <form method="POST" action="{{ route('tenant.billing.pay') }}">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <input type="hidden" name="cycle" value="{{ $cycle }}">
                                    <input type="hidden" name="gateway" value="sslcommerz">
                                    <button class="w-full py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">
                                        পেমেন্ট করুন
                                    </button>
                                </form>
                                <p class="text-[11px] text-mute text-center mt-1.5">বিকাশ, নগদ, রকেট, কার্ড — গেটওয়ের পেজেই বেছে নিতে পারবেন</p>
                            @elseif ($hasBkash)
                                <form method="POST" action="{{ route('tenant.billing.pay') }}">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <input type="hidden" name="cycle" value="{{ $cycle }}">
                                    <input type="hidden" name="gateway" value="bkash">
                                    <button class="w-full py-2.5 rounded-lg bg-[#E2136E] text-white font-semibold text-sm hover:opacity-90">bKash দিয়ে পেমেন্ট করুন</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>

@if ($payments->isNotEmpty())
    <div class="bg-white rounded-xl border border-ink/5 mt-8 max-w-4xl">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">পেমেন্ট হিস্ট্রি</div>
        @foreach ($payments as $p)
            <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <span class="text-mute">{{ $p->created_at->format('d M Y') }} · {{ $p->gateway }} · {{ $p->trx_id }}</span>
                <span><span class="font-semibold">{{ number_format($p->amount) }}৳</span>
                    <span class="ml-2 px-2 py-0.5 rounded text-xs {{ ['completed' => 'bg-leaf/10 text-leafdk', 'failed' => 'bg-red-50 text-red-600'][$p->status] ?? 'bg-amber/15' }}">{{ $p->status }}</span></span>
            </div>
        @endforeach
    </div>
@endif
@endsection
