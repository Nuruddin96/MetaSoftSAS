@extends('layouts.super')
@section('title', 'অ্যাপ ভার্সন ইতিহাস — '.$tenant->store_name)
@section('content')
<a href="{{ route('super.app-versions') }}" class="text-leafdk hover:underline text-sm">← অ্যাপ ভার্সন তালিকায় ফিরুন</a>
<h1 class="font-disp font-bold text-2xl mt-2 mb-6">📱 {{ $tenant->store_name }} — ভার্সন ইতিহাস</h1>

<h2 class="font-semibold text-sm text-mute mb-2">বর্তমান</h2>
<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto mb-8">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">ব্যবহারকারী</th>
            <th class="px-4 py-3">Version</th>
            <th class="px-4 py-3">Build</th>
            <th class="px-4 py-3">Device</th>
            <th class="px-4 py-3">Android</th>
            <th class="px-4 py-3">সর্বশেষ দেখা</th>
            <th class="px-4 py-3">Status</th>
        </tr></thead>
        <tbody>
        @forelse ($current as $row)
            @php $status = $statusFor($row); @endphp
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 font-medium">{{ $row->user?->name ?? '—' }}</td>
                <td class="px-4 py-3">{{ $row->app_version }}</td>
                <td class="px-4 py-3">{{ $row->app_build }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->device_model ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->os_version ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $status['class'] }}">{{ $status['label'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-mute">এই টেনেন্ট এখনো কোনো ভার্সন রিপোর্ট করেনি।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<h2 class="font-semibold text-sm text-mute mb-2">ইতিহাস (পুরনো → নতুন)</h2>
<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">ব্যবহারকারী</th>
            <th class="px-4 py-3">Version</th>
            <th class="px-4 py-3">Build</th>
            <th class="px-4 py-3">Device</th>
            <th class="px-4 py-3">Android</th>
            <th class="px-4 py-3">প্রথম দেখা</th>
            <th class="px-4 py-3">সর্বশেষ দেখা</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">উৎস</th>
        </tr></thead>
        <tbody>
        @forelse ($history as $row)
            @php $status = $statusFor($row); @endphp
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 font-medium">{{ $row->user?->name ?? '—' }}</td>
                <td class="px-4 py-3">{{ $row->app_version }}</td>
                <td class="px-4 py-3">{{ $row->app_build }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->device_model ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->os_version ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->first_seen_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->last_seen_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $status['class'] }}">{{ $status['label'] }}</span></td>
                <td class="px-4 py-3 text-mute text-xs">
                    {{ $row->source === 'app_version_report' ? 'সরাসরি রিপোর্ট' : 'পুরনো তথ্য (Remote Support নিবন্ধন থেকে)' }}
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="px-4 py-8 text-center text-mute">কোনো ইতিহাস নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
