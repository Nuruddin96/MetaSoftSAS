@extends('layouts.super')
@section('title', 'অ্যাপ ভার্সন')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📱 অ্যাপ ভার্সন</h1>
<p class="text-mute text-sm mb-6">
    প্রতিটি টেনেন্ট বর্তমানে কোন Business App ভার্সন/build ব্যবহার করছে — শুধু তথ্য প্রদর্শন, এখান থেকে কোনো আপডেট চালু হয় না।
    বর্তমান কনফিগ: latest build <strong>{{ $config?->latest_build ?? '—' }}</strong>,
    minimum supported build <strong>{{ $config?->minimum_supported_build ?? '—' }}</strong>
    (<a href="{{ route('super.app-update') }}" class="text-leafdk underline">এখান থেকে পরিবর্তন করুন</a>)।
</p>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="টেনেন্টের নাম..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-56">
    <input name="build" value="{{ request('build') }}" placeholder="Build নম্বর" type="number"
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-36">
    <input name="version" value="{{ request('version') }}" placeholder="Version (যেমন 1.1.1)"
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-40">
    <select name="status" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
        <option value="">সব Status</option>
        @foreach ($statusOptions as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
        @endforeach
    </select>
    <select name="sort" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
        <option value="activity" @selected(request('sort', 'activity') === 'activity')>সর্বশেষ Activity</option>
        <option value="build" @selected(request('sort') === 'build')>Build নম্বর</option>
    </select>
    <button class="px-4 py-2.5 rounded-lg bg-leaf text-white text-sm font-semibold hover:bg-leafdk">ফিল্টার</button>
    <a href="{{ route('super.app-versions') }}" class="px-4 py-2.5 rounded-lg border border-ink/15 text-sm hover:bg-paper/60">রিসেট</a>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">টেনেন্ট</th>
            <th class="px-4 py-3">Version</th>
            <th class="px-4 py-3">Build</th>
            <th class="px-4 py-3">Device</th>
            <th class="px-4 py-3">Android</th>
            <th class="px-4 py-3">সর্বশেষ দেখা</th>
            <th class="px-4 py-3">Status</th>
        </tr></thead>
        <tbody>
        @forelse (($rows ?? []) as $row)
            @php $status = $statusFor($row); @endphp
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                <td class="px-4 py-3 font-medium">{{ $row->tenant?->store_name ?? '—' }}</td>
                <td class="px-4 py-3">{{ $row->app_version }}</td>
                <td class="px-4 py-3">{{ $row->app_build }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->device_model ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->os_version ?? '—' }}</td>
                <td class="px-4 py-3 text-mute">{{ $row->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $status['class'] }}">{{ $status['label'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-12 text-center text-mute">
                @if (! $rows)
                    এখনো কোনো টেনেন্ট App Version পাঠায়নি — নতুন build (11+) ইনস্টল হলে এখানে দেখা যাবে।
                @else
                    কোনো তথ্য মেলেনি।
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if ($rows)
    <div class="mt-4">{{ $rows->links() }}</div>
@endif
@endsection
