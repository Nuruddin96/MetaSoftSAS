@extends('layouts.platform')

@section('title', 'List your brand — MetaSoft BD')
@section('meta_description', 'List your Bangladeshi brand on MetaSoft BD for free — a permanent profile, your own link, and a chance at national awards.')

@section('page')
@php
    // Compact controls; 16px text on phones so iOS Safari doesn't zoom into each field.
    $input = 'mt-1 w-full rounded-lg border bg-white px-3 py-2.5 text-base focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20 sm:py-2 sm:text-[14.5px]';
    $ok = 'border-hair';
    $bad = 'border-rose-400';
    $label = 'text-[13px] font-bold text-night';
    $error = 'mt-1 text-xs font-semibold text-rose-600';
    $optionalOpen = old('sub_category') || old('description') || old('website') || old('instagram') || $errors->hasAny(['sub_category', 'description', 'website', 'instagram']);
@endphp
<script type="application/json" id="bdLocations">@json($locations)</script>

<section class="mx-auto grid max-w-6xl gap-5 px-4 py-6 sm:px-6 sm:py-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-10">
    <div class="min-w-0 lg:sticky lg:top-24 lg:self-start">
        <p class="whitespace-nowrap text-[11px] font-extrabold uppercase tracking-[0.12em] text-golddk sm:text-[12px]">Free for every Bangladeshi brand</p>
        <h1 class="mt-1.5 text-balance text-[24px] font-extrabold leading-tight tracking-tight sm:text-[30px]">List your brand on MetaSoft BD</h1>
        <p class="mt-1 font-body text-[15px] text-leafdk sm:text-base" lang="bn">আপনার ব্র্যান্ডের স্থায়ী ডিজিটাল পরিচয় তৈরি করুন।</p>

        <ol class="mt-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
            @foreach([
                ['Register in 2 minutes', 'Basic details now. Add more later.'],
                ['We review your brand', 'Usually within 24 hours. Track status from your dashboard.'],
                ['Get your brand link', 'Share your profile, get nominated, and collect votes.'],
            ] as $i => [$t, $d])
                <li class="flex gap-2.5 rounded-xl border border-hair bg-white px-3 py-2.5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-leaf text-[12px] font-extrabold text-white">{{ $i + 1 }}</span>
                    <div class="min-w-0"><p class="text-[13.5px] font-bold leading-snug">{{ $t }}</p><p class="text-[12.5px] leading-snug text-slate2">{{ $d }}</p></div>
                </li>
            @endforeach
        </ol>

        <div class="mt-3 flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2.5 ring-1 ring-hair">
            <span class="text-[13.5px] font-semibold text-slate2">Already registered?</span>
            <a href="{{ route('owner.login') }}" class="inline-flex h-9 shrink-0 items-center justify-center rounded-lg bg-night px-4 text-[13.5px] font-bold text-white hover:bg-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">Log in</a>
        </div>
    </div>

    <form method="POST" action="{{ route('owner.register.store') }}" enctype="multipart/form-data" class="min-w-0 rounded-2xl border border-hair bg-white p-4 shadow-sm sm:p-6" novalidate>
        @csrf
        <div class="absolute -left-[9999px]" aria-hidden="true"><label>Company website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>

        @if($errors->any())
            <div role="alert" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700">
                @error('account')
                    <p class="font-bold">{{ $message }}</p>
                    <p class="mt-1"><a href="{{ route('owner.login') }}" class="font-bold underline">Log in</a> · <a href="{{ route('owner.password.request') }}" class="font-bold underline">Reset your password</a></p>
                @else
                    <p class="font-bold">Please check the highlighted fields.</p>
                @enderror
            </div>
        @endif

        <h2 class="text-base font-extrabold sm:text-[17px]">Your brand</h2>
        <div class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] items-start gap-3">
            <div>
                <span class="{{ $label }}">Logo <span class="text-rose-600">*</span></span>
                <label class="mt-1 flex h-[84px] w-[84px] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border-2 border-dashed {{ $errors->has('logo') ? $bad : 'border-hair' }} bg-cloud text-center text-[11px] font-semibold text-slate2 hover:border-leaf sm:h-[92px] sm:w-[92px]">
                    <img id="logoPreview" alt="" class="hidden h-full w-full object-cover">
                    <span id="logoPlaceholder" class="px-1.5"><x-plat.icon name="user-plus" class="mx-auto mb-0.5 w-4 h-4" /> Upload logo</span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="sr-only" data-preview="#logoPreview" data-preview-hide="#logoPlaceholder">
                </label>
                <p class="mt-1 max-w-[92px] text-[10.5px] leading-tight text-slate2">JPG/PNG/WebP, max 2 MB</p>
                @error('logo')<p class="{{ $error }} max-w-[9rem]">{{ $message }}</p>@enderror
            </div>
            <div class="grid min-w-0 gap-3">
                <label class="block min-w-0">
                    <span class="{{ $label }}">Brand name <span class="text-rose-600">*</span></span>
                    <input type="text" name="brand_name" value="{{ old('brand_name') }}" required maxlength="150" class="{{ $input }} {{ $errors->has('brand_name') ? $bad : $ok }}">
                    @error('brand_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Category <span class="text-rose-600">*</span></span>
                    <select name="brand_category_id" required class="{{ $input }} {{ $errors->has('brand_category_id') ? $bad : $ok }}">
                        <option value="">Select a category</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('brand_category_id') == $c->id)>{{ $c->name }}@if($c->bn_name) — {{ $c->bn_name }}@endif</option>
                        @endforeach
                    </select>
                    @error('brand_category_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block min-w-0">
                <span class="{{ $label }}">Division <span class="text-rose-600">*</span></span>
                <select name="division" required data-division-select class="{{ $input }} {{ $errors->has('division') ? $bad : $ok }}">
                    <option value="">Select division</option>
                    @foreach(array_keys($locations) as $d)
                        <option value="{{ $d }}" @selected(old('division') === $d)>{{ $d }}</option>
                    @endforeach
                </select>
                @error('division')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0">
                <span class="{{ $label }}">District <span class="text-rose-600">*</span></span>
                <select name="district" required data-district-select data-value="{{ old('district') }}" class="{{ $input }} {{ $errors->has('district') ? $bad : $ok }}">
                    <option value="">Select a division first</option>
                    @if(old('division'))
                        @foreach($locations[old('division')] ?? [] as $d)
                            <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option>
                        @endforeach
                    @endif
                </select>
                @error('district')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0 sm:col-span-2">
                <span class="{{ $label }}">Facebook Page link <span class="text-rose-600">*</span></span>
                <input type="text" name="facebook" value="{{ old('facebook') }}" required maxlength="255" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false"
                       pattern="{{ \App\Rules\FacebookPageUrl::HTML_PATTERN }}" title="Your business’s Facebook Page link, e.g. https://www.facebook.com/yourbrand"
                       placeholder="facebook.com/yourbrand" aria-describedby="facebookHelp" class="{{ $input }} {{ $errors->has('facebook') ? $bad : $ok }}">
                <span id="facebookHelp" class="mt-1 block text-[11.5px] leading-snug text-slate2">Shown on your profile so customers can find your page. Adding a link doesn’t prove you run the page; our team reviews every registration.</span>
                @error('facebook')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
        </div>

        <h2 class="mt-6 text-base font-extrabold sm:text-[17px]">Owner / founder</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block min-w-0 sm:col-span-2">
                <span class="{{ $label }}">Owner / founder name <span class="text-rose-600">*</span></span>
                <input type="text" name="founder_name" value="{{ old('founder_name') }}" required maxlength="150" autocomplete="name" class="{{ $input }} {{ $errors->has('founder_name') ? $bad : $ok }}">
                @error('founder_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0">
                <span class="{{ $label }}">Mobile number <span class="text-rose-600">*</span></span>
                <input type="tel" name="phone" value="{{ old('phone') }}" required inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" class="{{ $input }} {{ $errors->has('phone') ? $bad : $ok }}">
                @error('phone')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0">
                <span class="{{ $label }}">Email address <span class="text-rose-600">*</span></span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="{{ $input }} {{ $errors->has('email') ? $bad : $ok }}">
                @error('email')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0">
                <span class="{{ $label }}">Password <span class="text-rose-600">*</span></span>
                <span class="relative block">
                    <input id="regPassword" type="password" name="password" required minlength="8" autocomplete="new-password" class="{{ $input }} pr-16 {{ $errors->has('password') ? $bad : $ok }}">
                    <button type="button" data-toggle-password="#regPassword" class="absolute right-1.5 top-1/2 mt-0.5 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-bold text-slate2 hover:text-night">Show</button>
                </span>
                <span class="mt-1 block text-[11px] text-slate2">At least 8 characters</span>
                @error('password')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>
            <label class="block min-w-0">
                <span class="{{ $label }}">Confirm password <span class="text-rose-600">*</span></span>
                <input type="password" name="password_confirmation" required autocomplete="new-password" class="{{ $input }} {{ $ok }}">
            </label>
        </div>

        {{-- Collapsible; the fields stay in the form when closed, so typed values are always submitted and restored after a validation error. --}}
        <details class="group mt-5 rounded-xl border border-hair" @if($optionalOpen) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3.5 py-2.5">
                <span class="min-w-0"><span class="block text-[14px] font-bold">Add more details (optional)</span>
                    <span class="block text-[12px] text-slate2">Skip for now. Add details later from your dashboard.</span></span>
                <x-plat.icon name="chevron-down" class="w-4 h-4 shrink-0 transition group-open:rotate-180" />
            </summary>
            <div class="grid gap-3 border-t border-hair p-3.5 sm:grid-cols-2">
                <label class="block min-w-0">
                    <span class="{{ $label }}">Sub-category</span>
                    <input type="text" name="sub_category" value="{{ old('sub_category') }}" maxlength="100" placeholder="e.g. Handloom sarees" class="{{ $input }} {{ $errors->has('sub_category') ? $bad : $ok }}">
                    @error('sub_category')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Website</span>
                    <input type="url" name="website" value="{{ old('website') }}" placeholder="https://" class="{{ $input }} {{ $errors->has('website') ? $bad : $ok }}">
                    @error('website')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0 sm:col-span-2">
                    <span class="{{ $label }}">Instagram</span>
                    <input type="url" name="instagram" value="{{ old('instagram') }}" maxlength="255" placeholder="https://instagram.com/yourbrand" class="{{ $input }} {{ $errors->has('instagram') ? $bad : $ok }}">
                    @error('instagram')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0 sm:col-span-2">
                    <span class="{{ $label }}">Business description</span>
                    <textarea name="description" rows="3" maxlength="2000" class="{{ $input }} {{ $errors->has('description') ? $bad : $ok }}" placeholder="What does your brand make or offer?">{{ old('description') }}</textarea>
                    @error('description')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
            </div>
        </details>

        <label class="mt-4 flex items-start gap-2.5 text-[13.5px] leading-snug">
            <input type="checkbox" name="terms" value="1" @checked(old('terms')) required class="mt-0.5 h-5 w-5 shrink-0 rounded border-hair text-leaf focus:ring-leaf">
            <span>I confirm these brand details are accurate and agree to MetaSoft BD's rules. No fake brands, impersonation, or vote manipulation. <span class="text-rose-600">*</span></span>
        </label>
        @error('terms')<p class="{{ $error }}">{{ $message }}</p>@enderror

        <button type="submit" class="mt-4 h-12 w-full rounded-xl bg-leaf text-[15px] font-bold text-white shadow-sm hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">Submit my brand for review</button>
        <p class="mt-2 text-center text-xs text-slate2">Your brand stays private until our team approves it.</p>
    </form>
</section>
@endsection

@section('footer')
    <x-plat.site-footer />
@endsection
