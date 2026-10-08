@extends('layouts.super')

@section('title', ($award->exists ? 'Edit award' : 'New award').' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $field = 'mt-1 w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $lbl = 'block text-sm font-semibold';
    $dt = fn ($f) => old($f, $award->{$f}?->format('Y-m-d\TH:i'));
@endphp
<a href="{{ $award->exists ? route('super.awards.show', $award) : route('super.awards.index') }}" class="text-sm text-mute hover:text-ink">← Back</a>
<h1 class="mb-5 mt-2 font-disp text-2xl font-bold">{{ $award->exists ? 'Edit '.$award->title : 'New award' }}</h1>
@if($errors->any())<div class="mb-4 max-w-3xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $award->exists ? route('super.awards.update', $award) : route('super.awards.store') }}" class="max-w-3xl space-y-4 rounded-xl border border-ink/5 bg-white p-5">
    @csrf @if($award->exists) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-[1fr_120px]">
        <label class="{{ $lbl }}">Award name *<input name="title" value="{{ old('title', $award->title) }}" required maxlength="200" placeholder="MetaSoft BD Brand Awards" class="{{ $field }}"></label>
        <label class="{{ $lbl }}">Year *<input type="number" name="year" value="{{ old('year', $award->year) }}" required min="2020" max="2100" class="{{ $field }}"></label>
    </div>
    <label class="{{ $lbl }}">Bangla name<input name="bn_title" value="{{ old('bn_title', $award->bn_title) }}" class="{{ $field }}"></label>
    <label class="{{ $lbl }}">Status *
        <select name="status" class="{{ $field }}">@foreach(\App\Models\Award::STATUSES as $k => $l)<option value="{{ $k }}" @selected(old('status', $award->status) === $k)>{{ $l }}</option>@endforeach</select>
        <span class="mt-1 block text-xs font-normal text-mute">Draft is hidden from the public. Owners can nominate only while “Nominations open” (and inside the dates below, if set).</span>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="{{ $lbl }}">Nominations open<input type="datetime-local" name="nomination_starts_at" value="{{ $dt('nomination_starts_at') }}" class="{{ $field }}"></label>
        <label class="{{ $lbl }}">Nominations close<input type="datetime-local" name="nomination_ends_at" value="{{ $dt('nomination_ends_at') }}" class="{{ $field }}"></label>
        <label class="{{ $lbl }}">Voting opens<input type="datetime-local" name="voting_starts_at" value="{{ $dt('voting_starts_at') }}" class="{{ $field }}"></label>
        <label class="{{ $lbl }}">Voting closes<input type="datetime-local" name="voting_ends_at" value="{{ $dt('voting_ends_at') }}" class="{{ $field }}"></label>
    </div>
    <p class="-mt-2 text-xs text-mute">Voting dates here are shown on the award page; the actual voting is controlled by a voting campaign.</p>
    <label class="{{ $lbl }}">Description<textarea name="description" rows="4" class="{{ $field }}">{{ old('description', $award->description) }}</textarea></label>
    <label class="{{ $lbl }}">Rules<textarea name="rules" rows="6" class="{{ $field }}">{{ old('rules', $award->rules) }}</textarea></label>
    <label class="{{ $lbl }}">Jury information<textarea name="jury_info" rows="3" class="{{ $field }}">{{ old('jury_info', $award->jury_info) }}</textarea></label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $award->is_featured))> Feature this award on the homepage</label>
    <button class="rounded-lg bg-leaf px-5 py-2.5 text-sm font-semibold text-white">{{ $award->exists ? 'Save' : 'Create award' }}</button>
</form>

@if($award->exists)
    <form method="POST" action="{{ route('super.awards.destroy', $award) }}" class="mt-6 max-w-3xl rounded-xl border border-red-200 bg-white p-4">
        @csrf @method('DELETE')
        <p class="text-sm font-semibold text-red-700">Delete this award</p>
        <p class="mb-2 text-xs text-mute">Removes its categories, nominations and results. Type DELETE to confirm.</p>
        <div class="flex gap-2"><input name="confirm" placeholder="DELETE" class="rounded-lg border border-ink/10 px-3 py-2 text-sm"><button class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white">Delete</button></div>
    </form>
@endif
@endsection
