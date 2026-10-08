@extends('layouts.super')

@section('title', 'Votes — '.$campaign->title)

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4';
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $flagLabels = ['shared_device' => 'Device used other numbers', 'no_device' => 'No device cookie', 'ip_burst' => 'IP burst (same nominee)', 'ip_heavy' => 'High daily IP volume'];
@endphp
<a href="{{ route('super.campaigns.show', $campaign) }}" class="text-sm text-mute hover:text-ink">← {{ $campaign->title }}</a>
<h1 class="mb-1 mt-2 font-disp text-2xl font-bold">🔍 Vote review</h1>
<p class="mb-5 text-sm text-mute">Flagged votes still count until you invalidate them. Every invalidation or restore needs a reason and is written to the audit log. Phone numbers are stored only as a masked value and a one-way hash.</p>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="mb-5 grid gap-4 lg:grid-cols-2">
    <section class="{{ $box }}">
        <h2 class="mb-2 font-bold">Busiest IPs (last 24 h)</h2>
        <table class="w-full text-sm">
            @forelse($topIps as $row)
                <tr class="border-t border-ink/5">
                    <td class="py-1.5 font-mono text-xs"><a href="{{ route('super.campaigns.votes', [$campaign, 'ip' => $row->ip]) }}" class="text-leaf hover:underline">{{ $row->ip }}</a></td>
                    <td class="py-1.5 text-right">{{ $row->n }} votes</td><td class="py-1.5 text-right text-xs text-mute">{{ $row->entries }} brand(s)</td>
                </tr>
            @empty
                <tr><td class="text-mute">No votes in the last 24 hours.</td></tr>
            @endforelse
        </table>
        <p class="mt-2 text-xs text-mute">Mobile networks (CGNAT) put many real people behind one IP — check the pattern before invalidating.</p>
    </section>
    <section class="{{ $box }}">
        <h2 class="mb-2 font-bold">Devices voting with several numbers</h2>
        <table class="w-full text-sm">
            @forelse($sharedDevices as $row)
                <tr class="border-t border-ink/5">
                    <td class="py-1.5 font-mono text-xs"><a href="{{ route('super.campaigns.votes', [$campaign, 'device' => $row->device_hash]) }}" class="text-leaf hover:underline">{{ substr($row->device_hash, 0, 12) }}…</a></td>
                    <td class="py-1.5 text-right">{{ $row->phones }} numbers</td><td class="py-1.5 text-right text-xs text-mute">{{ $row->n }} votes</td>
                </tr>
            @empty
                <tr><td class="text-mute">None found.</td></tr>
            @endforelse
        </table>
    </section>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-ink/5 bg-white p-3">
    <select name="status" class="{{ $field }}"><option value="">Valid & invalid</option><option value="valid" @selected(request('status') === 'valid')>Valid</option><option value="invalid" @selected(request('status') === 'invalid')>Invalidated</option></select>
    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="flagged" value="1" @checked(request()->boolean('flagged'))> Flagged only</label>
    <input name="ip" value="{{ request('ip') }}" placeholder="IP" class="{{ $field }} w-36 font-mono">
    @if(request('device'))<input type="hidden" name="device" value="{{ request('device') }}"><span class="rounded bg-ink/5 px-2 py-1 font-mono text-xs">device {{ substr(request('device'), 0, 12) }}…</span>@endif
    @if(request('entry'))<input type="hidden" name="entry" value="{{ request('entry') }}"><span class="rounded bg-ink/5 px-2 py-1 text-xs">one nominee</span>@endif
    <button class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">Filter</button>
    @if(request()->query())<a href="{{ route('super.campaigns.votes', $campaign) }}" class="text-sm text-mute">Reset</a>@endif
</form>

<form id="invalidateForm" method="POST" action="{{ route('super.campaigns.votes.invalidate', $campaign) }}" class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-red-200 bg-red-50/50 p-3"
      onsubmit="return confirm('Invalidate the selected votes? They will be removed from the totals.')">
    @csrf
    <span class="text-sm font-semibold text-red-700">Invalidate:</span>
    <span class="text-xs text-mute">ticked votes</span>
    @if(request('ip'))<label class="flex items-center gap-1 text-xs"><input type="checkbox" name="ip" value="{{ request('ip') }}"> + every valid vote from IP {{ request('ip') }}</label>@endif
    @if(request('device'))<label class="flex items-center gap-1 text-xs"><input type="checkbox" name="device" value="{{ request('device') }}"> + every valid vote from this device</label>@endif
    <input name="reason" required minlength="5" maxlength="255" placeholder="Reason (required, audited)" class="{{ $field }} min-w-[220px] flex-1">
    <button class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white">Invalidate</button>
</form>

<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead><tr class="border-b border-ink/10 text-left text-mute">
            <th class="p-2"><input type="checkbox" onclick="document.querySelectorAll('[data-vote-cb]').forEach(c => c.checked = this.checked)" aria-label="Select all"></th>
            <th class="p-2">Time</th><th class="p-2">Nominee</th><th class="p-2">Phone</th><th class="p-2">IP</th><th class="p-2">Device</th><th class="p-2">Flags</th><th class="p-2">Status</th>
        </tr></thead>
        <tbody>
            @forelse($votes as $v)
                <tr class="border-b border-ink/5 align-top {{ $v->status === 'invalid' ? 'bg-red-50/40 text-mute' : '' }}">
                    <td class="p-2">@if($v->status === 'valid')<input type="checkbox" form="invalidateForm" name="ids[]" value="{{ $v->id }}" data-vote-cb>@endif</td>
                    <td class="whitespace-nowrap p-2 text-xs">{{ $v->created_at?->format('d M H:i:s') }}</td>
                    <td class="p-2">{{ $v->entry?->brand?->name }}<p class="text-xs text-mute">{{ $v->entry?->category?->name }}</p></td>
                    <td class="p-2 font-mono text-xs">{{ $v->phone_masked }}</td>
                    <td class="p-2 font-mono text-xs"><a href="{{ route('super.campaigns.votes', [$campaign, 'ip' => $v->ip]) }}" class="hover:underline">{{ $v->ip }}</a></td>
                    <td class="p-2 font-mono text-xs">@if($v->device_hash)<a href="{{ route('super.campaigns.votes', [$campaign, 'device' => $v->device_hash]) }}" class="hover:underline">{{ substr($v->device_hash, 0, 8) }}</a>@else — @endif</td>
                    <td class="p-2 text-xs">@foreach($v->flagList() as $f)<span class="mb-0.5 mr-0.5 inline-block rounded bg-amber/20 px-1.5 py-0.5 text-amber-800">{{ $flagLabels[$f] ?? $f }}</span>@endforeach</td>
                    <td class="p-2 text-xs">
                        @if($v->status === 'valid')
                            <span class="font-semibold text-leafdk">valid</span>
                        @else
                            <span class="font-semibold text-red-600">invalid</span><p class="max-w-[180px]">{{ $v->invalid_reason }}</p>
                            <form method="POST" action="{{ route('super.campaigns.votes.restore', $v) }}" class="mt-1 flex gap-1">@csrf
                                <input name="reason" required minlength="5" placeholder="Restore reason" class="w-28 rounded border border-ink/10 px-1.5 py-1 text-xs">
                                <button class="text-xs text-leaf hover:underline">Restore</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-6 text-center text-mute">No votes match.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $votes->links() }}</div>
@endsection
