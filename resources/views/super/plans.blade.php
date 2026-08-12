@extends('layouts.super')

@section('title', 'প্ল্যান')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">প্ল্যান ম্যানেজমেন্ট</h1>

<div class="grid lg:grid-cols-3 gap-6">
    @foreach ($plans as $plan)
        <form method="POST" action="{{ route('super.plans.update', $plan) }}" class="bg-white rounded-xl border border-ink/5 p-6 space-y-3">
            @csrf @method('PUT')
            <input name="name" value="{{ $plan->name }}" class="w-full font-bold rounded-lg border border-ink/15 px-3 py-2">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-xs text-mute">মাসিক দাম</label>
                    <input name="price_monthly" type="number" step="0.01" value="{{ $plan->price_monthly }}" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm"></div>
                <div><label class="text-xs text-mute">বাৎসরিক দাম</label>
                    <input name="price_yearly" type="number" step="0.01" value="{{ $plan->price_yearly }}" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="text-xs text-mute">প্রোডাক্ট</label>
                    <input name="max_products" type="number" value="{{ $plan->max_products }}" placeholder="∞" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm"></div>
                <div><label class="text-xs text-mute">স্টাফ</label>
                    <input name="max_staff" type="number" value="{{ $plan->max_staff }}" placeholder="∞" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm"></div>
                <div><label class="text-xs text-mute">গুদাম</label>
                    <input name="max_warehouses" type="number" value="{{ $plan->max_warehouses }}" placeholder="∞" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm"></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_pos" value="1" @checked($plan->allow_pos)> POS বিক্রি</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_custom_domain" value="1" @checked($plan->allow_custom_domain)> কাস্টম ডোমেইন</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_meta_ads" value="1" @checked($plan->allow_meta_ads)> অ্যাডভার্টাইজিং বিলিং মডিউল</label>

            @if ($featuresReady)
                <div class="pt-2 border-t border-ink/10">
                    <p class="text-xs font-semibold text-mute mb-2">ফিচার (ল্যান্ডিং পেজে দেখাবে)</p>
                    <div class="grid grid-cols-1 gap-1.5">
                        @foreach ($featureList as $key => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="features[]" value="{{ $key }}" @checked($plan->hasFeature($key))> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <label class="flex items-center gap-2 text-sm pt-1"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> অ্যাক্টিভ (ল্যান্ডিংয়ে দেখাবে)</label>
            <button class="w-full py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
        </form>
    @endforeach
</div>
<p class="text-xs text-mute mt-4">খালি রাখা মানে আনলিমিটেড।</p>
@if (! $featuresReady)
    <p class="text-xs text-amber-600 mt-2">ফিচার তালিকা এখনো প্রস্তুত হয়নি — database/sql/chunk27.sql ইম্পোর্ট করার পর এখানে দেখাবে।</p>
@endif
@endsection
