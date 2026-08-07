@extends('layouts.central')
@section('title', 'অ্যাফিলিয়েট লগইন')
@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <h1 class="font-disp font-bold text-2xl text-center mb-6">💰 অ্যাফিলিয়েট লগইন</h1>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            @error('email')<p class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>@enderror
            <form method="POST" action="{{ route('affiliate.login.attempt') }}" class="space-y-4">
                @csrf
                <input type="email" name="email" required autofocus placeholder="ইমেইল" class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <input type="password" name="password" required placeholder="পাসওয়ার্ড" class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
                <button class="w-full py-3.5 rounded-xl bg-amber text-ink font-bold hover:opacity-90">লগইন করুন</button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            নতুন? <a href="{{ route('affiliate.register') }}" class="text-leaf font-semibold hover:underline">অ্যাফিলিয়েট হন</a>
        </p>
    </div>
</div>
@endsection
