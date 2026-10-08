@extends('layouts.brand-owner')

@section('title', 'Account — MetaSoft BD')

@section('page')
@php $input = 'mt-1.5 w-full rounded-xl border bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20'; @endphp
<h1 class="text-[24px] font-extrabold tracking-tight sm:text-[28px]">Account</h1>

<section class="mt-5 rounded-[20px] border border-hair bg-white p-5 sm:p-6">
    <h2 class="font-extrabold">Login details</h2>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
        <div><dt class="text-slate2">Name</dt><dd class="font-bold">{{ $owner->name }}</dd></div>
        <div><dt class="text-slate2">Email</dt><dd class="break-all font-bold">{{ $owner->email }}</dd></div>
        <div><dt class="text-slate2">Mobile</dt><dd class="font-bold">{{ $owner->phone }}</dd></div>
    </dl>
    <p class="mt-3 text-xs text-slate2">You can log in with either your email or mobile number. To change them, contact MetaSoft BD support.</p>
</section>

<section class="mt-5 max-w-xl rounded-[20px] border border-hair bg-white p-5 sm:p-6">
    <h2 class="font-extrabold">Change password</h2>
    <form method="POST" action="{{ route('owner.account.password') }}" class="mt-4 space-y-4">
        @csrf @method('PUT')
        <label class="block">
            <span class="text-sm font-bold">Current password</span>
            <input type="password" name="current_password" required autocomplete="current-password" class="{{ $input }} {{ $errors->has('current_password') ? 'border-rose-400' : 'border-hair' }}">
            @error('current_password')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
        </label>
        <label class="block">
            <span class="text-sm font-bold">New password</span>
            <input type="password" name="password" required minlength="8" autocomplete="new-password" class="{{ $input }} {{ $errors->has('password') ? 'border-rose-400' : 'border-hair' }}">
            @error('password')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
        </label>
        <label class="block">
            <span class="text-sm font-bold">Confirm new password</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="{{ $input }} border-hair">
        </label>
        <button class="w-full rounded-xl bg-night py-3 text-sm font-bold text-white hover:bg-navy sm:w-auto sm:px-6">Update password</button>
    </form>
</section>
@endsection
