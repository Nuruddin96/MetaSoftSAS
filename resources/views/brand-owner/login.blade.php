@extends('layouts.platform')

@section('title', 'Brand owner login — MetaSoft BD')

@section('page')
@php $input = 'mt-1.5 w-full rounded-xl border border-hair bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20'; @endphp
<section class="mx-auto max-w-md px-4 py-10 sm:py-16">
    <div class="rounded-[24px] border border-hair bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-extrabold tracking-tight">Brand owner login</h1>
        <p class="mt-1 text-sm text-slate2">Manage your brand profile, voting and awards.</p>

        <form method="POST" action="{{ route('owner.login.attempt') }}" class="mt-6 space-y-4">
            @csrf
            <label class="block">
                <span class="text-sm font-bold">Email or mobile number</span>
                <input type="text" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" class="{{ $input }} {{ $errors->has('login') ? 'border-rose-400' : '' }}">
                @error('login')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-bold">Password</span>
                <span class="relative block">
                    <input id="loginPassword" type="password" name="password" required autocomplete="current-password" class="{{ $input }} pr-16">
                    <button type="button" data-toggle-password="#loginPassword" class="absolute right-2 top-1/2 mt-[3px] -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-bold text-slate2 hover:text-night">Show</button>
                </span>
            </label>
            <label class="flex items-center gap-2 text-sm text-slate2">
                <input type="checkbox" name="remember" value="1" checked class="h-4 w-4 rounded border-hair text-leaf focus:ring-leaf"> Keep me logged in
            </label>
            <button class="w-full rounded-xl bg-night py-3.5 text-[15px] font-bold text-white hover:bg-navy">Log in</button>
        </form>

        <p class="mt-5 text-center text-sm text-slate2">
            Forgot your password?
            @if($whatsapp)
                <a href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('I need help resetting my MetaSoft BD brand owner password.') }}" target="_blank" rel="noopener" class="font-bold text-leaf hover:underline">Contact support on WhatsApp</a>
            @else
                Contact MetaSoft BD support.
            @endif
        </p>
    </div>
    <p class="mt-6 text-center text-sm text-slate2">New here? <a href="{{ route('owner.register') }}" class="font-bold text-leaf hover:underline">List your brand for free</a></p>
</section>
@endsection
