@extends('layouts.brand-owner')

@section('title', 'My brand — MetaSoft BD')

@section('page')
@php
    $input = 'mt-1.5 w-full rounded-xl border bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20';
    $label = 'text-sm font-bold text-night';
    $card = 'rounded-[20px] border border-hair bg-white p-5 sm:p-6';
    $err = fn ($f) => $errors->has($f) ? 'border-rose-400' : 'border-hair';
    $review = fn ($f) => in_array($f, $reviewFields, true);
    $tag = '<span class="ml-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[10.5px] font-bold text-sky-700">Needs review</span>';
    $opt = '<span class="ml-1.5 rounded-full bg-cloud px-2 py-0.5 text-[10.5px] font-bold text-slate2">Optional</span>';
    $pending = $brand->pendingChange;
    $fieldNames = ['name' => 'Brand name', 'logo_path' => 'Logo', 'brand_category_id' => 'Category', 'district' => 'District', 'division' => 'Division', 'founder_name' => 'Founder name', 'phone' => 'Phone', 'email' => 'Email'];
    [$from, $to] = $brand->gradient();
@endphp
<script type="application/json" id="bdLocations">@json($locations)</script>

<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-[24px] font-extrabold tracking-tight sm:text-[28px]">My brand</h1>
        <p class="mt-0.5 text-sm text-slate2">Profile {{ $completion['percent'] }}% complete · optional details never block your listing.</p>
    </div>
</div>

@if($reviewFields)
    <p class="mt-4 flex items-start gap-2 rounded-2xl bg-sky-50 px-4 py-3 text-sm text-sky-800">
        <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" />
        <span>Fields marked <b>Needs review</b> identify your brand publicly. Changes to them are checked by our team first — your current details stay visible until then. Everything else updates instantly.</span>
    </p>
@endif

@if($pending)
    <section class="mt-4 rounded-[20px] border border-sky-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-extrabold">Changes pending review</h2>
            <form method="POST" action="{{ route('owner.brand.change.cancel') }}">@csrf @method('DELETE')
                <button class="text-sm font-bold text-rose-600 hover:underline">Cancel request</button>
            </form>
        </div>
        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            @foreach($pending->changes as $field => $value)
                <div class="rounded-xl bg-cloud px-3 py-2">
                    <dt class="text-xs font-semibold text-slate2">{{ $fieldNames[$field] ?? $field }}</dt>
                    <dd class="font-bold">
                        @if($field === 'logo_path')
                            <img src="{{ asset('storage/'.$value) }}" alt="Requested logo" class="mt-1 h-12 w-12 rounded-lg object-cover">
                        @elseif($field === 'brand_category_id')
                            {{ $categories->firstWhere('id', $value)?->name ?? '—' }}
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-2 text-xs text-slate2">Submitted {{ $pending->updated_at->diffForHumans() }}</p>
    </section>
@endif

<form method="POST" action="{{ route('owner.brand.update') }}" enctype="multipart/form-data" class="mt-5 space-y-5" novalidate>
    @csrf @method('PUT')
    @if($errors->any())
        <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">Please check the highlighted fields.</div>
    @endif

    <section class="{{ $card }}">
        <h2 class="font-extrabold">Brand identity</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-[auto_1fr]">
            <div>
                <span class="{{ $label }}">Logo {!! $review('logo_path') ? $tag : '' !!}</span>
                <label class="mt-1.5 block h-28 w-28 cursor-pointer overflow-hidden rounded-2xl border border-hair hover:border-leaf">
                    @if($brand->logoUrl())
                        <img id="logoPreview" src="{{ $brand->logoUrl() }}" alt="Current logo" class="h-full w-full object-cover">
                    @else
                        <img id="logoPreview" alt="" class="hidden h-full w-full object-cover">
                        <span id="logoPlaceholder"><x-plat.logo :initials="$brand->initials()" :from="$from" :to="$to" :size="112" /></span>
                    @endif
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="sr-only" data-preview="#logoPreview" data-preview-hide="#logoPlaceholder">
                </label>
                <p class="mt-1 text-[11px] text-slate2">Tap to change · max 2 MB</p>
                @error('logo')<p class="mt-1 max-w-[9rem] text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="{{ $label }}">Brand name {!! $review('name') ? $tag : '' !!}</span>
                    <input type="text" name="name" value="{{ old('name', $brand->name) }}" required maxlength="150" class="{{ $input }} {{ $err('name') }}">
                    @error('name')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="{{ $label }}">Category {!! $review('brand_category_id') ? $tag : '' !!}</span>
                    <select name="brand_category_id" required class="{{ $input }} {{ $err('brand_category_id') }}">
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('brand_category_id', $brand->brand_category_id) == $c->id)>{{ $c->name }}</option>
                        @endforeach
                        @if($brand->category && ! $categories->contains('id', $brand->brand_category_id))
                            <option value="{{ $brand->brand_category_id }}" selected disabled>{{ $brand->category->name }} (retired)</option>
                        @endif
                    </select>
                    @error('brand_category_id')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="{{ $label }}">Sub-category {!! $opt !!}</span>
                    <input type="text" name="sub_category" value="{{ old('sub_category', $brand->sub_category) }}" maxlength="100" class="{{ $input }} {{ $err('sub_category') }}">
                </label>
                <label class="block">
                    <span class="{{ $label }}">Division {!! $review('division') ? $tag : '' !!}</span>
                    <select name="division" required data-division-select class="{{ $input }} {{ $err('division') }}">
                        @foreach(array_keys($locations) as $d)
                            <option value="{{ $d }}" @selected(old('division', $brand->division) === $d)>{{ $d }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="{{ $label }}">District {!! $review('district') ? $tag : '' !!}</span>
                    <select name="district" required data-district-select data-value="{{ old('district', $brand->district) }}" class="{{ $input }} {{ $err('district') }}">
                        @foreach($locations[old('division', $brand->division)] ?? [] as $d)
                            <option value="{{ $d }}" @selected(old('district', $brand->district) === $d)>{{ $d }}</option>
                        @endforeach
                    </select>
                    @error('district')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
            </div>
        </div>
    </section>

    <section class="{{ $card }}">
        <h2 class="font-extrabold">Owner & contact</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="{{ $label }}">Owner / founder name {!! $review('founder_name') ? $tag : '' !!}</span>
                <input type="text" name="founder_name" value="{{ old('founder_name', $brand->founder_name) }}" required maxlength="150" class="{{ $input }} {{ $err('founder_name') }}">
                @error('founder_name')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Brand phone {!! $review('phone') ? $tag : '' !!}</span>
                <input type="tel" name="phone" value="{{ old('phone', $brand->phone) }}" required inputmode="numeric" class="{{ $input }} {{ $err('phone') }}">
                @error('phone')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="{{ $label }}">Brand email {!! $review('email') ? $tag : '' !!}</span>
                <input type="email" name="email" value="{{ old('email', $brand->email) }}" required class="{{ $input }} {{ $err('email') }}">
                @error('email')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
        </div>
        <p class="mt-3 text-xs text-slate2">These are your brand’s contact details. Your login email/phone are under <a href="{{ route('owner.account') }}" class="font-semibold underline">Account</a>.</p>
    </section>

    <section class="{{ $card }}">
        <h2 class="font-extrabold">About your business {!! $opt !!}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
                <span class="{{ $label }}">Business description</span>
                <textarea name="description" rows="5" maxlength="2000" class="{{ $input }} {{ $err('description') }}" placeholder="Your story, what you make, who you serve…">{{ old('description', $brand->description) }}</textarea>
            </label>
            <label class="block sm:col-span-2">
                <span class="{{ $label }}">Products / services</span>
                <textarea name="products_info" rows="4" maxlength="3000" class="{{ $input }} {{ $err('products_info') }}" placeholder="Main products or services, price range, delivery areas…">{{ old('products_info', $brand->products_info) }}</textarea>
            </label>
            <label class="block">
                <span class="{{ $label }}">Year founded</span>
                <input type="number" name="founded_year" value="{{ old('founded_year', $brand->founded_year) }}" min="1900" max="{{ now()->year }}" class="{{ $input }} {{ $err('founded_year') }}">
                @error('founded_year')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
            </label>
        </div>
    </section>

    <section class="{{ $card }}">
        <h2 class="font-extrabold">Website & social links {!! $opt !!}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach(['website' => 'Website', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $f => $l)
                <label class="block">
                    <span class="{{ $label }} inline-flex items-center gap-1.5">@include('partials.icon', ['platform' => $f, 'class' => 'w-4 h-4 text-slate2']) {{ $l }}</span>
                    <input type="url" name="{{ $f }}" value="{{ old($f, $brand->{$f}) }}" placeholder="https://" class="{{ $input }} {{ $err($f) }}">
                    @error($f)<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                </label>
            @endforeach
        </div>
    </section>

    <div class="sticky bottom-20 z-10 flex justify-end lg:bottom-4">
        <button class="w-full rounded-xl bg-leaf px-6 py-3.5 text-[15px] font-bold text-white shadow-lg hover:bg-leafdk sm:w-auto">Save changes</button>
    </div>
</form>

<section class="{{ $card }} mt-5">
    <div class="flex items-center justify-between">
        <h2 class="font-extrabold">Gallery {!! $opt !!}</h2>
        <span class="text-sm text-slate2">{{ count($brand->gallery ?? []) }} / {{ $galleryMax }}</span>
    </div>
    @if($brand->gallery)
        <div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
            @foreach($brand->galleryUrls() as $i => $src)
                <div class="relative aspect-square overflow-hidden rounded-xl bg-cloud">
                    <img src="{{ $src }}" alt="Gallery photo {{ $i + 1 }}" loading="lazy" class="h-full w-full object-cover">
                    <form method="POST" action="{{ route('owner.brand.gallery.destroy', $i) }}" class="absolute right-1.5 top-1.5">@csrf @method('DELETE')
                        <button class="flex h-7 w-7 items-center justify-center rounded-full bg-night/75 text-white hover:bg-night" aria-label="Remove photo {{ $i + 1 }}"><x-plat.icon name="x" class="w-4 h-4" /></button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
    @if(count($brand->gallery ?? []) < $galleryMax)
        <form method="POST" action="{{ route('owner.brand.gallery.store') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
            @csrf
            <input type="file" name="images[]" multiple accept="image/png,image/jpeg,image/webp" required
                   class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-cloud file:px-4 file:py-2.5 file:text-sm file:font-bold hover:file:bg-hair">
            <button class="shrink-0 rounded-xl bg-night px-5 py-2.5 text-sm font-bold text-white hover:bg-navy">Upload photos</button>
        </form>
        @error('images')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
        @error('images.*')<p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-slate2">Product photos, your shop or workshop, your team. JPG/PNG/WebP, max 3 MB each.</p>
    @endif
</section>
@endsection
