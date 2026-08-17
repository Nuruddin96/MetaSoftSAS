@extends('layouts.central')

@section('title', 'লগইন — MetaSoft BD')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <a href="/" class="flex items-center gap-2 justify-center mb-8">
            <x-ui.brand-mark />
            <span class="font-disp font-bold text-xl">MetaSoft BD</span>
        </a>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-2xl text-center">লগইন করুন</h1>
            <p class="text-mute text-sm text-center mt-2">আপনার শপের ইমেইল ও পাসওয়ার্ড দিন</p>

            @error('email')<p class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>@enderror

            <form method="POST" action="{{ route('central.login.attempt') }}" class="mt-6 space-y-4">
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
                    {{-- Defaults to checked — matches tenant/login.blade.php's
                         existing convention. Reuses Laravel's built-in
                         remember-me (SessionGuard + users.remember_token,
                         already wired in CentralLoginController::login())
                         rather than a shorter session-only login being the
                         default a tenant has to opt out of remembering. --}}
                    <label class="flex items-center gap-2"><input type="checkbox" name="remember" checked class="rounded"> মনে রাখুন</label>
                    <a href="{{ route('central.password.forgot') }}" class="text-leaf hover:underline">পাসওয়ার্ড ভুলে গেছেন?</a>
                </div>
                <button class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">লগইন করুন</button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            শপ নেই? <a href="{{ route('register') }}" class="text-leaf font-semibold hover:underline">ফ্রিতে খুলুন</a>
        </p>
    </div>
</div>
@endsection
