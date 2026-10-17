@extends('layouts.platform')

@section('title', 'Choose a new password — MetaSoft BD')
@section('robots', 'noindex')

@section('page')
@php $input = 'mt-1.5 w-full rounded-xl border border-hair bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20'; @endphp
<section class="mx-auto max-w-md px-4 py-10 sm:py-16">
    <div class="rounded-[24px] border border-hair bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-extrabold tracking-tight">Choose a new password</h1>
        <p class="mt-1 text-sm text-slate2">Use at least 8 characters.</p>

        <form method="POST" action="{{ route('owner.password.update') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block">
                <span class="text-sm font-bold">Email address</span>
                <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" class="{{ $input }} {{ $errors->has('email') ? 'border-rose-400' : '' }}">
                @error('email')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-bold">New password</span>
                <span class="relative block">
                    <input id="resetPassword" type="password" name="password" required minlength="8" autocomplete="new-password" class="{{ $input }} pr-16 {{ $errors->has('password') ? 'border-rose-400' : '' }}">
                    <button type="button" data-toggle-password="#resetPassword" class="absolute right-2 top-1/2 mt-[3px] -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-bold text-slate2 hover:text-night">Show</button>
                </span>
                @error('password')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="text-sm font-bold">Confirm new password</span>
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="{{ $input }}">
            </label>
            <button class="w-full rounded-xl bg-night py-3.5 text-[15px] font-bold text-white hover:bg-navy">Save new password</button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm text-slate2"><a href="{{ route('owner.password.request') }}" class="font-bold text-leaf hover:underline">Request a new link</a></p>
</section>
@endsection
