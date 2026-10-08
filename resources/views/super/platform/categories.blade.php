@extends('layouts.super')

@section('title', 'Brand categories — Brand platform')

@section('content')
@include('super.platform._tabs')
@php $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm'; @endphp
<h1 class="mb-1 font-disp text-2xl font-bold">🗂 Brand categories</h1>
<p class="mb-5 text-sm text-mute">Shown in registration, the directory filters and award eligibility. A category that brands use is deactivated instead of deleted. Sub-categories are free text on each brand and never required.</p>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ route('super.brand-categories.store') }}" enctype="multipart/form-data" class="mb-6 flex flex-wrap items-end gap-2 rounded-xl border border-ink/5 bg-white p-4">
    @csrf
    <label class="text-sm font-semibold">Name *<input name="name" required maxlength="100" class="{{ $field }} mt-1 block"></label>
    <label class="text-sm font-semibold">Bangla name<input name="bn_name" maxlength="100" class="{{ $field }} mt-1 block"></label>
    <label class="text-sm font-semibold">Icon<select name="icon" class="{{ $field }} mt-1 block"><option value="">—</option>@foreach($icons as $i)<option>{{ $i }}</option>@endforeach</select></label>
    <label class="text-sm font-semibold">Color<input type="color" name="color" value="#128155" class="mt-1 block h-[38px] w-14 rounded-lg border border-ink/10"></label>
    <label class="text-sm font-semibold">Image<input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="{{ $field }} mt-1 block w-52"></label>
    <button class="rounded-lg bg-leaf px-4 py-2 text-sm font-semibold text-white">+ Add category</button>
</form>

<form id="reorderForm" method="POST" action="{{ route('super.brand-categories.reorder') }}">@csrf</form>
<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead><tr class="border-b border-ink/10 text-left text-mute"><th class="p-3">Order</th><th class="p-3">Category</th><th class="p-3">Brands</th><th class="p-3">Edit</th><th class="p-3"></th></tr></thead>
        <tbody>
            @foreach($categories as $c)
                <tr class="border-b border-ink/5 align-top {{ $c->is_active ? '' : 'opacity-60' }}">
                    <td class="p-3"><input form="reorderForm" type="number" name="order[{{ $c->id }}]" value="{{ $c->sort_order }}" min="0" max="9999" class="{{ $field }} w-20"></td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            @if($c->imageUrl())<img src="{{ $c->imageUrl() }}" alt="" class="h-8 w-8 rounded object-cover">@else<span class="flex h-8 w-8 items-center justify-center rounded" style="background: {{ $c->color ?? '#64748B' }}20; color: {{ $c->color ?? '#64748B' }}">@if($c->icon)<x-plat.icon :name="$c->icon" class="w-4 h-4" />@endif</span>@endif
                            <div><p class="font-semibold">{{ $c->name }}</p><p class="text-xs text-mute">{{ $c->bn_name }} · /{{ $c->slug }}{{ $c->is_active ? '' : ' · inactive' }}</p></div>
                        </div>
                    </td>
                    <td class="p-3">{{ $c->brands_count }}</td>
                    <td class="p-3">
                        <details>
                            <summary class="cursor-pointer text-leaf">Edit</summary>
                            <form method="POST" action="{{ route('super.brand-categories.update', $c) }}" enctype="multipart/form-data" class="mt-2 grid gap-2 sm:grid-cols-2">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $c->name }}" required class="{{ $field }}">
                                <input name="bn_name" value="{{ $c->bn_name }}" placeholder="Bangla name" class="{{ $field }}">
                                <select name="icon" class="{{ $field }}"><option value="">No icon</option>@foreach($icons as $i)<option @selected($c->icon === $i)>{{ $i }}</option>@endforeach</select>
                                <input type="color" name="color" value="{{ $c->color ?? '#64748B' }}" class="h-[38px] w-full rounded-lg border border-ink/10">
                                <input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="{{ $field }} sm:col-span-2">
                                <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active</label>
                                <button class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">Save</button>
                            </form>
                        </details>
                    </td>
                    <td class="p-3 text-right">
                        <form method="POST" action="{{ route('super.brand-categories.destroy', $c) }}" onsubmit="return confirm('{{ $c->brands_count ? 'Deactivate' : 'Delete' }} {{ addslashes($c->name) }}?')">@csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">{{ $c->brands_count ? 'Deactivate' : 'Delete' }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<button form="reorderForm" class="mt-3 rounded-lg border border-ink/10 bg-white px-4 py-2 text-sm font-semibold">Save order</button>
@endsection
