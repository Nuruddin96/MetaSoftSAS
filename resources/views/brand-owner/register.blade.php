@extends('layouts.platform')

@section('title', 'List your brand — MetaSoft BD')
@section('meta_description', 'List your Bangladeshi brand on MetaSoft BD for free — a permanent profile, your own link, and a chance at national awards.')
@section('compact-header', '1')

@push('head')
<style>
    /* Design 4. The homepage "Building Bangladesh" map (x-plat.bd-network) reused as a faint, static backdrop — page-scoped; the homepage map is untouched. */
    .reg-map .bd-viz { position: static !important; opacity: 1 !important; width: min(100%, 1040px); margin-inline: auto; }
    .reg-map .bd-stage { width: 100% !important; }
    .reg-map .bd-chip, .reg-map .bd-legend, .reg-map .bd-label { display: none !important; }
</style>
@endpush

@section('page')
@php
    // Compact controls. Typed text stays 16px on phones so iOS Safari doesn't zoom into a field; placeholders are smaller.
    $input = 'mt-0.5 block w-full rounded-md border bg-white px-2.5 py-[5px] text-base leading-5 placeholder:text-[13px] placeholder:text-slate-400 focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20 sm:py-1 sm:text-[13px] sm:leading-[18px] sm:placeholder:text-[12px]';
    $ok = 'border-hair';
    $bad = 'border-rose-400';
    $label = 'block text-[12px] font-bold leading-4 text-night';
    $error = 'mt-0.5 text-[11.5px] font-semibold text-rose-600';
    $upload = 'mt-0.5 flex h-[72px] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-lg border-2 border-dashed bg-cloud text-center text-[10.5px] font-semibold leading-tight text-slate2 hover:border-leaf sm:h-20';
    $optionalOpen = old('sub_category') || old('description') || old('website') || old('instagram') || $errors->hasAny(['sub_category', 'description', 'website', 'instagram']);
    $whatsapp = preg_replace('/\D/', '', (string) config('payment.support_whatsapp'));
    // The ministry line is backed by the published DBID certificate (RJSC, Ministry of Commerce) — hidden without it.
    $certificate = \App\Support\Home\HomeMedia::certificate();
@endphp
<script type="application/json" id="bdLocations">@json($locations)</script>

{{-- Navy banner: title, the ministry line with a "Registered" label (DBID registration, linked to the certificate — not an endorsement), and the steps.
     No government seal: there is no record of permission to use it. --}}
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#052B4C_0%,#073B66_55%,#1769AA_100%)] pb-12 text-white sm:pb-14">
    <div class="relative mx-auto max-w-4xl px-4 pt-4 sm:px-6 sm:pt-6">
        <div class="relative">
            <h1 class="text-[24px] font-extrabold leading-tight tracking-tight sm:text-[30px]">List Your Brand</h1>
            @if($certificate)
            <p class="mt-0.5 flex flex-wrap items-center gap-x-1 gap-y-1 text-[10.5px] max-[359px]:flex-nowrap max-[359px]:gap-x-[3px] max-[359px]:text-[9.5px] max-[359px]:tracking-[-0.01em] font-semibold text-white/80 min-[390px]:text-[11.5px] sm:gap-x-2 sm:text-[12.5px]">
                <span class="max-[359px]:whitespace-nowrap">Ministry of Commerce, Government of Bangladesh</span>
                <a href="{{ url('/') }}#credentials" title="MetaSoft BD is registered under the Digital Business Identity (DBID) Guidelines 2022 with RJSC, Ministry of Commerce. This is a registration, not a government endorsement."
                   class="inline-flex h-[18px] shrink-0 items-center rounded bg-gold px-1 text-[9px] max-[359px]:h-4 max-[359px]:px-[3px] max-[359px]:text-[8px] max-[359px]:tracking-normal font-extrabold uppercase tracking-[0.02em] sm:h-5 sm:px-1.5 sm:text-[10px] sm:tracking-[0.08em] text-night hover:brightness-105">Registered</a>
            </p>
            @endif
        </div>
        <p class="relative mt-3 whitespace-nowrap text-[12.5px] font-semibold text-white/85 sm:hidden">Get approved. Share your profile. Collect votes.</p>
        <ol class="relative mt-4 hidden gap-2 sm:grid sm:grid-cols-2">
            @foreach([['Register in 2 minutes', 'Basic details now, more later from your dashboard.'], ['Get your brand link', 'Once approved, share your profile, get nominated and collect votes.']] as $i => [$t, $d])
                <li class="flex items-start gap-2 rounded-lg bg-white/10 px-2.5 py-2"><span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-leaf text-[11px] font-extrabold text-white">{{ $i + 1 }}</span><span class="min-w-0"><span class="block text-[13px] font-bold leading-tight">{{ $t }}</span><span class="block text-[12px] leading-snug text-white/70">{{ $d }}</span></span></li>
            @endforeach
        </ol>
    </div>
</section>

<section class="relative isolate">
    {{-- Faint, static "Building Bangladesh" map behind the form. --}}
    <div class="reg-map bd-paused pointer-events-none absolute inset-x-0 top-6 -z-10 px-4 opacity-[.07]" aria-hidden="true"><x-plat.bd-network /></div>

    <div class="relative mx-auto -mt-9 max-w-4xl px-3 pb-6 sm:px-6">
        <form method="POST" action="{{ route('owner.register.store') }}" enctype="multipart/form-data" class="rounded-2xl bg-white/80 p-3.5 shadow-[0_24px_50px_-28px_rgba(11,37,69,0.55)] ring-1 ring-white/70 backdrop-blur-md sm:p-5" novalidate>
            @csrf
            <div class="absolute -left-[9999px]" aria-hidden="true"><label>Company website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>

            <div class="flex items-center gap-2">
                <div class="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-lg bg-white px-2.5 py-1 ring-1 ring-hair">
                    <span class="truncate text-[12.5px] font-semibold text-slate2">Already registered?</span>
                    <a href="{{ route('owner.login') }}" class="inline-flex h-7 shrink-0 items-center rounded-md bg-night px-3 text-[12.5px] font-bold text-white hover:bg-navy">Log in</a>
                </div>
                @if($whatsapp)
                    <a href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('Hi MetaSoft BD, I need help listing my brand.') }}" target="_blank" rel="noopener" title="WhatsApp support"
                       class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-[#25D366] px-2.5 text-[12.5px] font-bold text-white hover:brightness-95">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4']) <span class="max-[359px]:sr-only">Support</span></a>
                @endif
            </div>

            @if($errors->any())
                <div role="alert" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[13px] text-rose-700">
                    @error('account')
                        <p class="font-bold">{{ $message }}</p>
                        <p class="mt-1"><a href="{{ route('owner.login') }}" class="font-bold underline">Log in</a> · <a href="{{ route('owner.password.request') }}" class="font-bold underline">Reset your password</a></p>
                    @else
                        <p class="font-bold">Please check the highlighted fields.</p>
                    @enderror
                </div>
            @endif

            <h2 class="mt-3 text-[14px] font-extrabold">Your brand</h2>
            {{-- Logo and (once chunk69.sql is imported) Cover Image side by side, same box style; each previews the chosen file. --}}
            <div class="mt-1 grid grid-cols-[72px_minmax(0,1fr)] gap-2 sm:grid-cols-[80px_minmax(0,1fr)]">
                <div class="min-w-0">
                    <span class="{{ $label }}">Logo <span class="text-rose-600">*</span></span>
                    <label class="{{ $upload }} {{ $errors->has('logo') ? $bad : 'border-hair' }}">
                        <img id="logoPreview" alt="" class="hidden h-full w-full object-cover">
                        <span id="logoPlaceholder" class="px-1"><x-plat.icon name="user-plus" class="mx-auto mb-0.5 w-4 h-4" /> Upload<span class="block font-normal">Max 2 MB</span></span>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="sr-only" data-preview="#logoPreview" data-preview-hide="#logoPlaceholder">
                    </label>
                </div>
                @if($coverEnabled)
                    <div class="min-w-0">
                        <span class="{{ $label }}">Cover Image <span class="font-normal text-slate2">(optional)</span></span>
                        <label class="{{ $upload }} {{ $errors->has('cover') ? $bad : 'border-hair' }}">
                            <img id="coverPreview" alt="" class="hidden h-full w-full object-cover">
                            <span id="coverPlaceholder" class="px-2"><x-plat.icon name="image" class="mx-auto mb-0.5 w-4 h-4" /> Upload cover<span class="block font-normal">Wide image, e.g. 1200×400 · Max 3 MB</span></span>
                            <input type="file" name="cover" accept="image/png,image/jpeg,image/webp" class="sr-only" data-preview="#coverPreview" data-preview-hide="#coverPlaceholder">
                        </label>
                    </div>
                @endif
            </div>
            @foreach(['logo', 'cover'] as $f)@error($f)<p class="{{ $error }}">{{ $message }}</p>@enderror @endforeach

            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="block min-w-0">
                    <span class="{{ $label }}">Brand name <span class="text-rose-600">*</span></span>
                    <input type="text" name="brand_name" value="{{ old('brand_name') }}" required maxlength="150" placeholder="Your brand’s name" class="{{ $input }} {{ $errors->has('brand_name') ? $bad : $ok }}">
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

            <div class="mt-2 grid grid-cols-2 gap-2">
                <label class="block min-w-0">
                    <span class="{{ $label }}">Division <span class="text-rose-600">*</span></span>
                    <select name="division" required data-division-select class="{{ $input }} {{ $errors->has('division') ? $bad : $ok }}">
                        <option value="">Select</option>
                        @foreach(array_keys($locations) as $d)
                            <option value="{{ $d }}" @selected(old('division') === $d)>{{ $d }}</option>
                        @endforeach
                    </select>
                    @error('division')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">District <span class="text-rose-600">*</span></span>
                    <select name="district" required data-district-select data-value="{{ old('district') }}" class="{{ $input }} {{ $errors->has('district') ? $bad : $ok }}">
                        <option value="">Select</option>
                        @if(old('division'))
                            @foreach($locations[old('division')] ?? [] as $d)
                                <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option>
                            @endforeach
                        @endif
                    </select>
                    @error('district')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
            </div>

            <label class="mt-2 block min-w-0">
                <span class="{{ $label }}">Facebook Page link <span class="text-rose-600">*</span> <span id="facebookHelp" class="font-normal text-slate2 max-sm:sr-only">· Adding a link doesn’t prove you run the page.</span></span>
                <input type="text" name="facebook" value="{{ old('facebook') }}" required maxlength="255" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false"
                       pattern="{{ \App\Rules\FacebookPageUrl::HTML_PATTERN }}" title="Your business’s Facebook Page link, e.g. https://www.facebook.com/yourbrand"
                       placeholder="facebook.com/page" aria-describedby="facebookHelp" class="{{ $input }} {{ $errors->has('facebook') ? $bad : $ok }}">
                @error('facebook')<p class="{{ $error }}">{{ $message }}</p>@enderror
            </label>

            <h2 class="mt-3 text-[14px] font-extrabold">Owner / founder</h2>
            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                <label class="block min-w-0 sm:col-span-2">
                    <span class="{{ $label }}">Owner / founder name <span class="text-rose-600">*</span></span>
                    <input type="text" name="founder_name" value="{{ old('founder_name') }}" required maxlength="150" autocomplete="name" placeholder="Full name" class="{{ $input }} {{ $errors->has('founder_name') ? $bad : $ok }}">
                    @error('founder_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Mobile number <span class="text-rose-600">*</span></span>
                    <input type="tel" name="phone" value="{{ old('phone') }}" required inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" class="{{ $input }} {{ $errors->has('phone') ? $bad : $ok }}">
                    @error('phone')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Email address <span class="text-rose-600">*</span></span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="you@email.com" class="{{ $input }} {{ $errors->has('email') ? $bad : $ok }}">
                    @error('email')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Password <span class="text-rose-600">*</span></span>
                    <span class="relative block">
                        <input id="regPassword" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="8+ characters" class="{{ $input }} pr-14 {{ $errors->has('password') ? $bad : $ok }}">
                        <button type="button" data-toggle-password="#regPassword" class="absolute right-1 top-1/2 mt-px -translate-y-1/2 rounded px-1.5 py-0.5 text-[11px] font-bold text-slate2 hover:text-night">Show</button>
                    </span>
                    @error('password')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </label>
                <label class="block min-w-0">
                    <span class="{{ $label }}">Confirm password <span class="text-rose-600">*</span></span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Repeat password" class="{{ $input }} {{ $ok }}">
                </label>
            </div>

            {{-- Collapsible; the fields stay in the form when closed, so typed values are always submitted and restored after a validation error. --}}
            <details class="group mt-2.5 rounded-lg border border-hair bg-white/70" @if($optionalOpen) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-2.5 py-1.5">
                    <span class="text-[13px] font-bold">Add more details (optional)</span>
                    <x-plat.icon name="chevron-down" class="w-4 h-4 shrink-0 transition group-open:rotate-180" />
                </summary>
                <div class="grid gap-2 border-t border-hair p-2.5 sm:grid-cols-2">
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
                        <textarea name="description" rows="2" maxlength="2000" class="{{ $input }} {{ $errors->has('description') ? $bad : $ok }}" placeholder="What does your brand make or offer?">{{ old('description') }}</textarea>
                        @error('description')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </label>
                </div>
            </details>

            <label class="mt-2.5 flex items-start gap-2 text-[12px] leading-snug">
                <input type="checkbox" name="terms" value="1" @checked(old('terms')) required class="mt-px h-4 w-4 shrink-0 rounded border-hair text-leaf focus:ring-leaf">
                <span>I own or represent this brand, the details are accurate, and I accept MetaSoft BD’s rules: no fake brands, impersonation or vote manipulation. <span class="text-rose-600">*</span></span>
            </label>
            @error('terms')<p class="{{ $error }}">{{ $message }}</p>@enderror

            <button type="submit" class="mt-2.5 h-9 w-full rounded-md bg-leaf text-[14px] font-bold text-white shadow-sm hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">Submit my brand for review</button>
            <p class="mt-1 text-center text-[11px] text-slate2">Your brand stays private until our team approves it.</p>
        </form>
    </div>
</section>
@endsection

@section('footer')
    <x-plat.site-footer />
@endsection
