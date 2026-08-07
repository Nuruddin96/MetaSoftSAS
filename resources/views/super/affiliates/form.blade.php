@extends('layouts.super')
@section('title', $affiliate ? 'অ্যাফিলিয়েট এডিট' : 'নতুন অ্যাফিলিয়েট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $affiliate ? 'অ্যাফিলিয়েট এডিট' : 'নতুন অ্যাফিলিয়েট যোগ করুন' }}</h1>

<form method="POST" action="{{ $affiliate ? route('super.affiliates.update', $affiliate) : route('super.affiliates.store') }}"
      class="max-w-xl bg-white rounded-xl border border-ink/5 p-6 space-y-4">
    @csrf
    @if ($affiliate) @method('PUT') @endif

    <div>
        <label class="text-sm font-medium">নাম *</label>
        <input name="name" value="{{ old('name', $affiliate?->name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
    </div>
    <div class="grid md:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium">ইমেইল *</label>
            <input type="email" name="email" value="{{ old('email', $affiliate?->email) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">ফোন *</label>
            <input name="phone" value="{{ old('phone', $affiliate?->phone) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
    </div>
    <div>
        <label class="text-sm font-medium">পাসওয়ার্ড {{ $affiliate ? '(খালি রাখলে বদলাবে না)' : '*' }}</label>
        <input type="password" name="password" {{ $affiliate ? '' : 'required' }} minlength="6" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
    </div>
    <div>
        <label class="text-sm font-medium">রেফারেল কোড {{ $affiliate ? '*' : '(খালি রাখলে অটো তৈরি হবে)' }}</label>
        <input name="referral_code" value="{{ old('referral_code', $affiliate?->referral_code) }}" {{ $affiliate ? 'required' : '' }}
               placeholder="AFFXXXXXX" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 uppercase">
    </div>
    <div class="grid md:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium">পেমেন্ট পদ্ধতি</label>
            <input name="payment_method" value="{{ old('payment_method', $affiliate?->payment_method) }}" placeholder="বিকাশ / নগদ / ব্যাংক" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">পেমেন্ট নাম্বার</label>
            <input name="payment_number" value="{{ old('payment_number', $affiliate?->payment_number) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
    </div>
    <div>
        <label class="text-sm font-medium">স্ট্যাটাস</label>
        <select name="status" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
            <option value="active" @selected(old('status', $affiliate?->status ?? 'active') === 'active')>অ্যাক্টিভ</option>
            <option value="suspended" @selected(old('status', $affiliate?->status) === 'suspended')>সাসপেন্ডেড</option>
        </select>
    </div>

    <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">{{ $affiliate ? 'আপডেট করুন' : 'যোগ করুন' }}</button>
</form>
@endsection
