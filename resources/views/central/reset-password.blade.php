@extends('layouts.central')

@section('title', 'নতুন পাসওয়ার্ড — MetaSoft BD')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <a href="/" class="flex items-center gap-2 justify-center mb-8">
            <x-ui.brand-mark />
            <span class="font-disp font-bold text-xl">MetaSoft BD</span>
        </a>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-xl text-center">নতুন পাসওয়ার্ড দিন</h1>

            @error('token')<p class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>@enderror

            <form method="POST" action="{{ route('central.password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="email" name="email" value="{{ $email }}" required readonly
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-paper text-mute">
                <input type="password" name="password" required minlength="6" placeholder="নতুন পাসওয়ার্ড"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <input type="password" name="password_confirmation" required placeholder="আবার লিখুন"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <button class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">পাসওয়ার্ড বদলান</button>
            </form>
        </div>
    </div>
</div>
@endsection
