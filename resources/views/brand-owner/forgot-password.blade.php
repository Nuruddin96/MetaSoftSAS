@extends('layouts.platform')

@section('title', 'Reset your password — MetaSoft BD')
@section('robots', 'noindex')

@section('page')
@php $input = 'mt-1.5 w-full rounded-xl border border-hair bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20'; @endphp
<section class="mx-auto max-w-md px-4 py-10 sm:py-16">
    <div class="rounded-[24px] border border-hair bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-extrabold tracking-tight">Forgot your password?</h1>

        @if($available)
            <p class="mt-1 text-sm text-slate2">Enter the email address you registered your brand with. If it matches an account, we will email you a link to choose a new password.</p>

            <form method="POST" action="{{ route('owner.password.email') }}" class="mt-6 space-y-4">
                @csrf
                <label class="block">
                    <span class="text-sm font-bold">Email address</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="{{ $input }} {{ $errors->has('email') ? 'border-rose-400' : '' }}">
                    @error('email')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <button class="w-full rounded-xl bg-night py-3.5 text-[15px] font-bold text-white hover:bg-navy">Send reset link</button>
            </form>
            <p class="mt-4 text-xs text-slate2">The link works once and expires after 60 minutes.</p>
        @else
            <p class="mt-2 text-sm text-slate2">Self-service password reset is not available yet.</p>
        @endif

        <p class="mt-5 text-center text-sm text-slate2">
            No access to that email?
            @if($whatsapp)
                <a href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('I need help resetting my MetaSoft BD brand owner password.') }}" target="_blank" rel="noopener" class="font-bold text-leaf hover:underline">Contact support on WhatsApp</a>
            @else
                Contact MetaSoft BD support.
            @endif
        </p>
    </div>
    <p class="mt-6 text-center text-sm text-slate2"><a href="{{ route('owner.login') }}" class="font-bold text-leaf hover:underline">Back to login</a></p>
</section>
@endsection
