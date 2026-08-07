@extends('layouts.central')

@section('title', 'সুপার অ্যাডমিন লগইন')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <p class="text-center font-disp font-bold text-2xl mb-6">⚡ সুপার অ্যাডমিন</p>
        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            @error('email')<p class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ $message }}</p>@enderror
            <form method="POST" action="{{ route('super.login.attempt') }}" class="space-y-4">
                @csrf
                <input type="email" name="email" required autofocus placeholder="ইমেইল"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <input type="password" name="password" required placeholder="পাসওয়ার্ড"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <button class="w-full py-3 rounded-xl bg-ink text-white font-bold hover:bg-ink/90">লগইন</button>
            </form>
        </div>
    </div>
</div>
@endsection
