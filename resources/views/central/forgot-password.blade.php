@extends('layouts.central')

@section('title', 'পাসওয়ার্ড রিসেট — MetaSoft BD')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <a href="/" class="flex items-center gap-2 justify-center mb-8">
            <x-ui.brand-mark />
            <span class="font-disp font-bold text-xl">MetaSoft BD</span>
        </a>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-xl text-center">পাসওয়ার্ড ভুলে গেছেন?</h1>
            <p class="text-mute text-sm text-center mt-2">ইমেইল দিন, রিসেট লিংক পাঠানো হবে</p>

            @if (session('success'))
                <p class="mt-4 bg-leaf/10 border border-leaf/30 text-leafdk text-sm rounded-lg p-3">{{ session('success') }}</p>
            @endif

            <form method="POST" action="{{ route('central.password.email') }}" class="mt-6 space-y-4">
                @csrf
                <input type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="আপনার ইমেইল"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <button class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">রিসেট লিংক পাঠান</button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            <a href="{{ route('central.login') }}" class="text-leaf font-semibold hover:underline">← লগইনে ফিরে যান</a>
        </p>
    </div>
</div>
@endsection
