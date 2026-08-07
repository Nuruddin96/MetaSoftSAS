@extends('layouts.super')
@section('title', 'টেনেন্ট এডিট')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">টেনেন্ট এডিট — {{ $tenant->store_name }}</h1>
    <a href="{{ route('super.tenants.show', $tenant) }}" class="text-sm text-mute hover:text-ink">← ফিরে যান</a>
</div>

<div class="grid lg:grid-cols-2 gap-6 max-w-4xl">
    <form method="POST" action="{{ route('super.tenants.update', $tenant) }}" class="bg-white rounded-xl border border-ink/5 p-6 space-y-4">
        @csrf @method('PUT')
        <p class="font-bold text-sm mb-1">দোকানের তথ্য</p>
        <div>
            <label class="text-sm font-medium">দোকানের নাম</label>
            <input name="store_name" value="{{ old('store_name', $tenant->store_name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">সাবডোমেইন</label>
            <div class="mt-1 flex rounded-lg border border-ink/15 overflow-hidden">
                <input name="subdomain" value="{{ old('subdomain', $tenant->subdomain) }}" required class="flex-1 px-3 py-2.5 outline-none">
                <span class="bg-paper px-3 py-2.5 text-mute text-sm">.metasoftbd.com</span>
            </div>
        </div>
        <div>
            <label class="text-sm font-medium">মালিকের নাম</label>
            <input name="owner_name" value="{{ old('owner_name', $tenant->owner_name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">মোবাইল নাম্বার</label>
            <input name="owner_phone" value="{{ old('owner_phone', $tenant->owner_phone) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">ইমেইল (লগইনের জন্য ব্যবহৃত)</label>
            <input type="email" name="owner_email" value="{{ old('owner_email', $tenant->owner_email) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
                <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">আপডেট করুন</button>
    </form>

    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-ink/5 p-6">
            <p class="font-bold text-sm mb-1">🔑 পাসওয়ার্ড রিসেট করুন</p>
            <p class="text-xs text-mute mb-4">টেনেন্ট পাসওয়ার্ড ভুলে গেলে বা হারিয়ে গেলে এখান থেকে নতুন পাসওয়ার্ড সেট করে দিন।</p>
            <form method="POST" action="{{ route('super.tenants.password.reset', $tenant) }}" class="space-y-3">
                @csrf
                <input type="password" name="password" required minlength="6" placeholder="নতুন পাসওয়ার্ড" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input type="password" name="password_confirmation" required placeholder="আবার লিখুন" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <button class="w-full py-2.5 rounded-lg bg-ink text-white font-semibold text-sm hover:bg-ink/90">পাসওয়ার্ড রিসেট করুন</button>
            </form>
        </div>

        <div class="bg-red-50 border border-red-200 rounded-xl p-6">
            <p class="font-bold text-sm mb-1 text-red-700">⚠️ বিপজ্জনক এলাকা</p>
            <p class="text-xs text-red-600 mb-4">এই টেনেন্ট মুছে ফেললে তার সব প্রোডাক্ট, অর্ডার, কাস্টমার, পেমেন্ট হিস্ট্রি — সবকিছু স্থায়ীভাবে মুছে যাবে। এটা ফিরিয়ে আনা যাবে না।</p>
            <form method="POST" action="{{ route('super.tenants.destroy', $tenant) }}"
                  onsubmit="return confirm('আপনি কি নিশ্চিত? {{ $tenant->store_name }} এবং তার সব ডেটা স্থায়ীভাবে মুছে যাবে!')">
                @csrf @method('DELETE')
                <button class="w-full py-2.5 rounded-lg bg-red-600 text-white font-semibold text-sm hover:bg-red-700">এই টেনেন্ট স্থায়ীভাবে মুছুন</button>
            </form>
        </div>
    </div>
</div>
@endsection
