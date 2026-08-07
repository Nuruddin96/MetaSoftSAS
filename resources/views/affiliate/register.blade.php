@extends('layouts.central')
@section('title', 'অ্যাফিলিয়েট রেজিস্ট্রেশন — MetaSoft BD')
@section('content')
<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <a href="/" class="flex items-center gap-2 justify-center mb-6">
            <span class="w-9 h-9 rounded bg-amber grid place-items-center text-ink font-bold text-lg">💰</span>
            <span class="font-disp font-bold text-xl">রেফার করে আয় করুন</span>
        </a>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-2xl text-center">অ্যাফিলিয়েট হিসেবে যোগ দিন</h1>
            <p class="text-mute text-sm text-center mt-2">রেফার করুন, আয় করুন — কোনো খরচ ছাড়াই</p>

            <ul class="mt-5 space-y-2 text-sm bg-amber/10 rounded-lg p-4">
                <li>🟢 SaaS দোকান রেফার করলে <b>প্রথম পেমেন্টের ২০%</b> (ওয়ান টাইম)</li>
                <li>🔵 সার্ভিস প্যাকেজের ক্লায়েন্ট রেফার করলে <b>১০০০৳ প্রতি মাসে</b> (যতদিন ক্লায়েন্ট চালু থাকবে, লাইফটাইম)</li>
            </ul>

            @if ($errors->any())
                <div class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
                    <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('affiliate.register.submit') }}" class="mt-6 space-y-4">
                @csrf
                <input name="name" value="{{ old('name') }}" required placeholder="আপনার নাম"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <input type="email" name="email" value="{{ old('email') }}" required placeholder="ইমেইল"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <input name="phone" value="{{ old('phone') }}" required placeholder="মোবাইল নাম্বার (01XXXXXXXXX)"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <input type="password" name="password" required minlength="6" placeholder="পাসওয়ার্ড"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <input type="password" name="password_confirmation" required placeholder="আবার পাসওয়ার্ড"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <button class="w-full py-3.5 rounded-xl bg-amber text-ink font-bold hover:opacity-90">অ্যাফিলিয়েট একাউন্ট খুলুন</button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            আগে থেকেই একাউন্ট আছে? <a href="{{ route('affiliate.login') }}" class="text-leaf font-semibold hover:underline">লগইন করুন</a>
        </p>
    </div>
</div>
@endsection
