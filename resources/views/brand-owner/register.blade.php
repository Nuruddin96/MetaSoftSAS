@extends('layouts.platform')

@section('title', 'List your brand — MetaSoft BD')
@section('meta_description', 'List your Bangladeshi brand on MetaSoft BD for free — a permanent profile, your own link, and a chance at national awards.')

@section('page')
@php
    $input = 'mt-1.5 w-full rounded-xl border bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20';
    $ok = 'border-hair';
    $bad = 'border-rose-400';
    $label = 'text-sm font-bold text-night';
    $optionalOpen = old('sub_category') || old('description') || old('website') || old('facebook');
@endphp
<script type="application/json" id="bdLocations">@json($locations)</script>

<section class="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:px-6 sm:py-12 lg:grid-cols-[0.85fr_1.15fr] lg:gap-12">
    <div class="lg:sticky lg:top-24 lg:self-start">
        <p class="text-[12px] font-extrabold uppercase tracking-[0.16em] text-leaf">Free for every Bangladeshi brand</p>
        <h1 class="mt-2 text-[30px] font-extrabold leading-tight tracking-tight sm:text-[40px]">List your brand on MetaSoft BD</h1>
        <p class="mt-2 font-body text-lg text-leafdk" lang="bn">আপনার ব্র্যান্ডের স্থায়ী ডিজিটাল পরিচয় তৈরি করুন</p>
        <ol class="mt-6 space-y-4">
            @foreach([
                ['Register in 2 minutes', 'Only the basics — you can add everything else later from your dashboard.'],
                ['Our team reviews it', 'Usually within 24 hours. You will see the status on your dashboard.'],
                ['Go live with your own link', 'metasoftbd.com/brand/your-brand — ready to share, nominate and collect votes.'],
            ] as $i => [$t, $d])
                <li class="flex gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-leaf text-sm font-extrabold text-white">{{ $i + 1 }}</span>
                    <div><p class="font-bold">{{ $t }}</p><p class="text-sm text-slate2">{{ $d }}</p></div>
                </li>
            @endforeach
        </ol>
        <p class="mt-6 text-sm text-slate2">Already registered? <a href="{{ route('owner.login') }}" class="font-bold text-leaf hover:underline">Log in to your dashboard</a></p>
    </div>

    <form method="POST" action="{{ route('owner.register.store') }}" enctype="multipart/form-data" class="rounded-[24px] border border-hair bg-white p-5 shadow-sm sm:p-8" novalidate>
        @csrf
        <div class="absolute -left-[9999px]" aria-hidden="true"><label>Company website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>

        @if($errors->any())
            <div role="alert" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <p class="font-bold">Please check the highlighted fields.</p>
            </div>
        @endif

        <h2 class="text-lg font-extrabold">Your brand</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-[auto_1fr] sm:items-start">
            <div>
                <span class="{{ $label }}">Logo <span class="text-rose-600">*</span></span>
                <label class="mt-1.5 flex h-28 w-28 cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed {{ $errors->has('logo') ? $bad : 'border-hair' }} bg-cloud text-center text-xs font-semibold text-slate2 hover:border-leaf">
                    <img id="logoPreview" alt="" class="hidden h-full w-full object-cover">
                    <span id="logoPlaceholder" class="px-2"><x-plat.icon name="user-plus" class="mx-auto mb-1 w-5 h-5" /> Upload logo</span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="sr-only" data-preview="#logoPreview" data-preview-hide="#logoPlaceholder">
                </label>
                <p class="mt-1 text-[11px] text-slate2">JPG/PNG/WebP, max 2 MB</p>
                @error('logo')<p class="mt-1 max-w-[9rem] text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-4">
                <label class="block">
                    <span class="{{ $label }}">Brand name <span class="text-rose-600">*</span></span>
                    <input type="text" name="brand_name" value="{{ old('brand_name') }}" required maxlength="150" class="{{ $input }} {{ $errors->has('brand_name') ? $bad : $ok }}">
                    @error('brand_name')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="{{ $label }}">Category <span class="text-rose-600">*</span></span>
                    <select name="brand_category_id" required class="{{ $input }} {{ $errors->has('brand_category_id') ? $bad : $ok }}">
                        <option value="">Select a category</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('brand_category_id') == $c->id)>{{ $c->name }}@if($c->bn_name) — {{ $c->bn_name }}@endif</option>
                        @endforeach
                    </select>
                    @error('brand_category_id')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="{{ $label }}">Division <span class="text-rose-600">*</span></span>
                <select name="division" required data-division-select class="{{ $input }} {{ $errors->has('division') ? $bad : $ok }}">
                    <option value="">Select division</option>
                    @foreach(array_keys($locations) as $d)
                        <option value="{{ $d }}" @selected(old('division') === $d)>{{ $d }}</option>
                    @endforeach
                </select>
                @error('division')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">District <span class="text-rose-600">*</span></span>
                <select name="district" required data-district-select data-value="{{ old('district') }}" class="{{ $input }} {{ $errors->has('district') ? $bad : $ok }}">
                    <option value="">Select a division first</option>
                    @if(old('division'))
                        @foreach($locations[old('division')] ?? [] as $d)
                            <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option>
                        @endforeach
                    @endif
                </select>
                @error('district')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
        </div>

        <h2 class="mt-8 text-lg font-extrabold">Owner / founder</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="{{ $label }}">Owner / founder name <span class="text-rose-600">*</span></span>
                <input type="text" name="founder_name" value="{{ old('founder_name') }}" required maxlength="150" autocomplete="name" class="{{ $input }} {{ $errors->has('founder_name') ? $bad : $ok }}">
                @error('founder_name')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Mobile number <span class="text-rose-600">*</span></span>
                <input type="tel" name="phone" value="{{ old('phone') }}" required inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" class="{{ $input }} {{ $errors->has('phone') ? $bad : $ok }}">
                @error('phone')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Email address <span class="text-rose-600">*</span></span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="{{ $input }} {{ $errors->has('email') ? $bad : $ok }}">
                @error('email')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Password <span class="text-rose-600">*</span></span>
                <span class="relative block">
                    <input id="regPassword" type="password" name="password" required minlength="8" autocomplete="new-password" class="{{ $input }} pr-16 {{ $errors->has('password') ? $bad : $ok }}">
                    <button type="button" data-toggle-password="#regPassword" class="absolute right-2 top-1/2 mt-[3px] -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-bold text-slate2 hover:text-night">Show</button>
                </span>
                <span class="mt-1 block text-[11px] text-slate2">At least 8 characters</span>
                @error('password')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Confirm password <span class="text-rose-600">*</span></span>
                <input type="password" name="password_confirmation" required autocomplete="new-password" class="{{ $input }} {{ $ok }}">
            </label>
        </div>

        <details class="group mt-8 rounded-2xl border border-hair" @if($optionalOpen) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3.5">
                <span><span class="font-bold">Add more details now</span> <span class="ml-1 rounded-full bg-cloud px-2 py-0.5 text-[11px] font-bold text-slate2">Optional</span>
                    <span class="mt-0.5 block text-xs text-slate2">You can skip this and add it later from your dashboard.</span></span>
                <x-plat.icon name="chevron-down" class="w-4 h-4 shrink-0 transition group-open:rotate-180" />
            </summary>
            <div class="grid gap-4 border-t border-hair p-4 sm:grid-cols-2">
                <label class="block">
                    <span class="{{ $label }}">Sub-category</span>
                    <input type="text" name="sub_category" value="{{ old('sub_category') }}" maxlength="100" placeholder="e.g. Handloom sarees" class="{{ $input }} {{ $ok }}">
                </label>
                <label class="block">
                    <span class="{{ $label }}">Website</span>
                    <input type="url" name="website" value="{{ old('website') }}" placeholder="https://" class="{{ $input }} {{ $errors->has('website') ? $bad : $ok }}">
                    @error('website')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="{{ $label }}">Facebook page</span>
                    <input type="url" name="facebook" value="{{ old('facebook') }}" placeholder="https://facebook.com/yourbrand" class="{{ $input }} {{ $errors->has('facebook') ? $bad : $ok }}">
                    @error('facebook')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="{{ $label }}">Business description</span>
                    <textarea name="description" rows="4" maxlength="2000" class="{{ $input }} {{ $ok }}" placeholder="What does your brand make or offer?">{{ old('description') }}</textarea>
                </label>
            </div>
        </details>

        <label class="mt-6 flex items-start gap-3 text-sm">
            <input type="checkbox" name="terms" value="1" @checked(old('terms')) required class="mt-0.5 h-5 w-5 shrink-0 rounded border-hair text-leaf focus:ring-leaf">
            <span>I own or represent this brand, the information is accurate, and I agree to the MetaSoft BD platform rules: no fake brands, no impersonation, and no buying or manipulating votes. <span class="text-rose-600">*</span></span>
        </label>
        @error('terms')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror

        <button type="submit" class="mt-6 w-full rounded-xl bg-leaf py-4 text-[15px] font-bold text-white shadow-sm hover:bg-leafdk">Submit my brand for review</button>
        <p class="mt-3 text-center text-xs text-slate2">Your brand stays private until our team approves it.</p>
    </form>
</section>
@endsection
