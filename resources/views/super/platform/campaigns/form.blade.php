@extends('layouts.super')

@section('title', ($campaign->exists ? 'Edit campaign' : 'New campaign').' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $field = 'mt-1 w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $lbl = 'block text-sm font-semibold';
@endphp
<a href="{{ $campaign->exists ? route('super.campaigns.show', $campaign) : route('super.campaigns.index') }}" class="text-sm text-mute hover:text-ink">← Back</a>
<h1 class="mb-5 mt-2 font-disp text-2xl font-bold">{{ $campaign->exists ? 'Edit '.$campaign->title : 'New voting campaign' }}</h1>
@if($errors->any())<div class="mb-4 max-w-3xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $campaign->exists ? route('super.campaigns.update', $campaign) : route('super.campaigns.store') }}" class="max-w-3xl space-y-4 rounded-xl border border-ink/5 bg-white p-5">
    @csrf @if($campaign->exists) @method('PUT') @endif
    <label class="{{ $lbl }}">Title *<input name="title" value="{{ old('title', $campaign->title) }}" required maxlength="200" placeholder="People’s Choice {{ now()->year }}" class="{{ $field }}"></label>
    <label class="{{ $lbl }}">Linked award
        <select name="award_id" class="{{ $field }}"><option value="">— none —</option>@foreach($awards as $a)<option value="{{ $a->id }}" @selected(old('award_id', $campaign->award_id) == $a->id)>{{ $a->title }}</option>@endforeach</select>
        <span class="mt-1 block text-xs font-normal text-mute">Lets you import the award’s categories and nominees in one click.</span>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="{{ $lbl }}">Voting starts<input type="datetime-local" name="starts_at" value="{{ old('starts_at', $campaign->starts_at?->format('Y-m-d\TH:i')) }}" class="{{ $field }}"></label>
        <label class="{{ $lbl }}">Voting ends<input type="datetime-local" name="ends_at" value="{{ old('ends_at', $campaign->ends_at?->format('Y-m-d\TH:i')) }}" class="{{ $field }}"></label>
    </div>
    <p class="-mt-2 text-xs text-mute">Times are Bangladesh time. Once started, a campaign opens and closes on these dates automatically; leave empty to open/close manually.</p>
    <label class="{{ $lbl }}">Vote limit
        <select name="vote_limit" class="{{ $field }}">
            <option value="daily" @selected(old('vote_limit', $campaign->vote_limit) === 'daily')>One vote per mobile number per category, every day</option>
            <option value="once" @selected(old('vote_limit', $campaign->vote_limit) === 'once')>One vote per mobile number per category, for the whole campaign</option>
        </select></label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_counts" value="1" @checked(old('show_counts', $campaign->show_counts))> Show live vote counts and rankings to the public</label>
    <label class="{{ $lbl }}">Description<textarea name="description" rows="4" class="{{ $field }}">{{ old('description', $campaign->description) }}</textarea></label>
    <button class="rounded-lg bg-leaf px-5 py-2.5 text-sm font-semibold text-white">{{ $campaign->exists ? 'Save' : 'Create as draft' }}</button>
</form>

@if($campaign->exists)
    <form method="POST" action="{{ route('super.campaigns.destroy', $campaign) }}" class="mt-6 max-w-3xl" onsubmit="return confirm('Delete this campaign?')">@csrf @method('DELETE')
        <button class="text-sm text-red-600 hover:underline">Delete campaign (only possible before any votes)</button>
    </form>
@endif
@endsection
