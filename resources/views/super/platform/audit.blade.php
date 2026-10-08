@extends('layouts.super')

@section('title', 'Audit log — Brand platform')

@section('content')
@include('super.platform._tabs')
@php $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm'; @endphp
<h1 class="mb-1 font-disp text-2xl font-bold">📜 Platform audit log</h1>
<p class="mb-5 text-sm text-mute">Who changed what, when and why — brands, verification, awards, campaigns and votes. Read-only.</p>

<form method="GET" class="mb-4 flex flex-wrap gap-2 rounded-xl border border-ink/5 bg-white p-3">
    <select name="subject" class="{{ $field }}"><option value="">All subjects</option>@foreach($subjects as $s)<option value="{{ $s }}" @selected(request('subject') === $s)>{{ $s }}</option>@endforeach</select>
    <input name="action" value="{{ request('action') }}" placeholder="Action starts with… (e.g. brand.verified)" class="{{ $field }} min-w-[240px]">
    <button class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead><tr class="border-b border-ink/10 text-left text-mute"><th class="p-3">When</th><th class="p-3">Who</th><th class="p-3">Action</th><th class="p-3">Subject</th><th class="p-3">Details</th></tr></thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="border-b border-ink/5 align-top">
                    <td class="whitespace-nowrap p-3 text-xs">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                    <td class="p-3">{{ $log->actor_name }}<p class="text-xs text-mute">{{ $log->actor_type }} · {{ $log->ip }}</p></td>
                    <td class="p-3 font-mono text-xs">{{ $log->action }}</td>
                    <td class="p-3 text-xs">
                        @if($log->subject_type === 'Brand' && $log->subject_id)<a href="{{ route('super.brands.show', $log->subject_id) }}" class="text-leaf hover:underline">Brand #{{ $log->subject_id }}</a>
                        @elseif($log->subject_type === 'VoteCampaign' && $log->subject_id)<a href="{{ route('super.campaigns.show', $log->subject_id) }}" class="text-leaf hover:underline">Campaign #{{ $log->subject_id }}</a>
                        @elseif($log->subject_type === 'Award' && $log->subject_id)<a href="{{ route('super.awards.show', $log->subject_id) }}" class="text-leaf hover:underline">Award #{{ $log->subject_id }}</a>
                        @else {{ $log->subject_type }} #{{ $log->subject_id }} @endif
                    </td>
                    <td class="max-w-md p-3 text-xs">
                        @if($log->reason)<p><b>Reason:</b> {{ $log->reason }}</p>@endif
                        @if($log->changes)<pre class="mt-1 max-h-32 overflow-auto whitespace-pre-wrap break-all rounded bg-paper p-2 text-[11px]">{{ json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-mute">No activity yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
