@extends('layouts.central')

@section('title', 'লগইন — MetaSoft BD')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <a href="/" class="flex items-center gap-2 justify-center mb-8">
            <x-ui.brand-mark />
            <span class="font-disp font-bold text-xl">MetaSoft BD</span>
        </a>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-2xl text-center">আপনার শপে লগইন</h1>
            <p class="text-mute text-sm text-center mt-2">শপের ওয়েবসাইট ঠিকানা লিখুন</p>

            @error('subdomain')
                <p class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('central.login.find') }}" class="mt-6">
                @csrf
                <div class="flex rounded-lg border border-ink/15 overflow-hidden focus-within:ring-2 focus-within:ring-leaf">
                    <input name="subdomain" required class="flex-1 px-3 py-3 outline-none" placeholder="rahimfashion" autofocus>
                    <span class="bg-ink/5 px-3 py-3 text-mute text-sm">.metasoftbd.com</span>
                </div>
                <button class="mt-4 w-full py-3.5 rounded-xl bg-ink text-white font-bold hover:bg-ink/90">এগিয়ে যান →</button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            শপ নেই? <a href="{{ route('register') }}" class="text-leaf font-semibold hover:underline">ফ্রিতে খুলুন</a>
        </p>
    </div>
</div>
@endsection
