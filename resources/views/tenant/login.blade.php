@extends('layouts.tenant-auth')

@section('title', app('currentTenant')->store_name . ' — প্যানেল লগইন')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <p class="font-disp font-bold text-2xl">{{ app('currentTenant')->store_name }}</p>
            <p class="text-mute text-sm mt-1">অ্যাডমিন প্যানেল</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            @if (request('registered'))
                <p class="mb-4 bg-leaf/10 border border-leaf/30 text-leafdk text-sm rounded-lg p-3">
                    🎉 অভিনন্দন! আপনার দোকান তৈরি হয়ে গেছে। ইমেইল ও পাসওয়ার্ড দিয়ে লগইন করুন।
                </p>
            @endif
            @error('email')
                <p class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('tenant.login.attempt') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="text-sm font-medium">ইমেইল</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                </div>
                <div>
                    <label class="text-sm font-medium">পাসওয়ার্ড</label>
                    <input type="password" name="password" required
                           class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                </div>
                <div class="flex items-center justify-between text-sm">
                    <label class="flex items-center gap-2 text-mute">
                        <input type="checkbox" name="remember" checked class="rounded"> মনে রাখুন
                    </label>
                    <a href="https://{{ config('app.central_domain') }}/forgot-password" class="text-leaf hover:underline">পাসওয়ার্ড ভুলে গেছেন?</a>
                </div>
                <button class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">লগইন করুন</button>
            </form>
        </div>
    </div>
</div>
@endsection
