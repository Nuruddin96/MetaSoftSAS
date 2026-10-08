@extends('layouts.super')

@section('title', ($brand->exists ? 'Edit '.$brand->name : 'Add brand').' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $field = 'mt-1 w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $lbl = 'block text-sm font-semibold';
    $e = fn ($f) => $errors->first($f) ? '<p class="mt-1 text-xs text-red-600">'.e($errors->first($f)).'</p>' : '';
@endphp
<a href="{{ $brand->exists ? route('super.brands.show', $brand) : route('super.brands.index') }}" class="text-sm text-mute hover:text-ink">← Back</a>
<h1 class="mb-5 mt-2 font-disp text-2xl font-bold">{{ $brand->exists ? 'Edit '.$brand->name : 'Add a brand manually' }}</h1>

<form method="POST" action="{{ $brand->exists ? route('super.brands.update', $brand) : route('super.brands.store') }}" enctype="multipart/form-data"
      class="max-w-3xl space-y-5 rounded-xl border border-ink/5 bg-white p-5">
    @csrf @if($brand->exists) @method('PUT') @endif
    <p class="text-xs text-mute">Admin edits apply immediately and are recorded in the audit log. Only the first section is required.</p>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="{{ $lbl }} sm:col-span-2">Brand name *<input name="name" value="{{ old('name', $brand->name) }}" required class="{{ $field }}">{!! $e('name') !!}</label>
        <label class="{{ $lbl }}">Logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="{{ $field }}">{!! $e('logo') !!}
            @if($brand->logoUrl())<img src="{{ $brand->logoUrl() }}" alt="" class="mt-2 h-14 w-14 rounded-lg object-cover">@endif</label>
        <label class="{{ $lbl }}">Category *
            <select name="brand_category_id" required class="{{ $field }}">
                <option value="">—</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('brand_category_id', $brand->brand_category_id) == $c->id)>{{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}</option>@endforeach
            </select>{!! $e('brand_category_id') !!}</label>
        <label class="{{ $lbl }}">Owner / founder name *<input name="founder_name" value="{{ old('founder_name', $brand->founder_name) }}" required class="{{ $field }}">{!! $e('founder_name') !!}</label>
        <label class="{{ $lbl }}">Phone *<input name="phone" value="{{ old('phone', $brand->phone) }}" required placeholder="01XXXXXXXXX" class="{{ $field }}">{!! $e('phone') !!}</label>
        <label class="{{ $lbl }}">Email *<input type="email" name="email" value="{{ old('email', $brand->email) }}" required class="{{ $field }}">{!! $e('email') !!}</label>
        <label class="{{ $lbl }}">Division *
            <select name="division" required class="{{ $field }}">
                <option value="">—</option>
                @foreach(array_keys($locations) as $d)<option value="{{ $d }}" @selected(old('division', $brand->division) === $d)>{{ $d }}</option>@endforeach
            </select>{!! $e('division') !!}</label>
        <label class="{{ $lbl }}">District *
            <select name="district" required class="{{ $field }}">
                <option value="">—</option>
                @foreach($locations as $div => $districts)
                    <optgroup label="{{ $div }}">@foreach($districts as $d)<option value="{{ $d }}" @selected(old('district', $brand->district) === $d)>{{ $d }}</option>@endforeach</optgroup>
                @endforeach
            </select>{!! $e('district') !!}</label>
        @if(! $brand->exists)
            <label class="{{ $lbl }}">Initial status
                <select name="status" class="{{ $field }}"><option value="approved">Approved (publish now)</option><option value="pending" @selected(old('status') === 'pending')>Pending review</option></select></label>
        @else
            <label class="{{ $lbl }}">URL slug <span class="font-normal text-mute">— changing it breaks links already shared</span>
                <input name="slug" value="{{ old('slug', $brand->slug) }}" placeholder="auto on approval" class="{{ $field }} font-mono">{!! $e('slug') !!}</label>
        @endif
    </div>

    <details class="rounded-lg border border-ink/10 p-4" @if($brand->description || $brand->website || $errors->hasAny(['website', 'facebook', 'instagram', 'tiktok', 'youtube', 'founded_year'])) open @endif>
        <summary class="cursor-pointer text-sm font-semibold">Optional details</summary>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="{{ $lbl }}">Sub-category<input name="sub_category" value="{{ old('sub_category', $brand->sub_category) }}" class="{{ $field }}"></label>
            <label class="{{ $lbl }}">Year founded<input type="number" name="founded_year" value="{{ old('founded_year', $brand->founded_year) }}" class="{{ $field }}">{!! $e('founded_year') !!}</label>
            <label class="{{ $lbl }} sm:col-span-2">Description<textarea name="description" rows="4" class="{{ $field }}">{{ old('description', $brand->description) }}</textarea></label>
            <label class="{{ $lbl }} sm:col-span-2">Products / services<textarea name="products_info" rows="3" class="{{ $field }}">{{ old('products_info', $brand->products_info) }}</textarea></label>
            @foreach(['website', 'facebook', 'instagram', 'tiktok', 'youtube'] as $f)
                <label class="{{ $lbl }}">{{ ucfirst($f) }}<input type="url" name="{{ $f }}" value="{{ old($f, $brand->{$f}) }}" placeholder="https://" class="{{ $field }}">{!! $e($f) !!}</label>
            @endforeach
        </div>
    </details>

    <button class="rounded-lg bg-leaf px-5 py-2.5 text-sm font-semibold text-white hover:bg-leafdk">{{ $brand->exists ? 'Save changes' : 'Add brand' }}</button>
</form>
@endsection
