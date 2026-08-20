@extends('layouts.super')
@section('title', 'রিমোট সাপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">🖥️ রিমোট সাপোর্ট</h1>
<p class="text-mute text-sm mb-6">শুধু Super Admin — কোনো টেনেন্ট এই সেটিংস দেখতে বা পরিবর্তন করতে পারে না।</p>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="স্টোরের নাম..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-72">
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">টেনেন্ট</th>
            <th class="px-4 py-3">রিমোট সাপোর্ট</th>
            <th class="px-4 py-3">ডিভাইস</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                <td class="px-4 py-3 font-medium cursor-pointer" onclick="window.location='{{ route('super.remote-support.show', $t) }}'">{{ $t->store_name }}</td>
                <td class="px-4 py-3">
                    @if ($t->remoteSupportSetting?->enabled)
                        <span class="px-2 py-1 rounded text-xs bg-leaf/10 text-leafdk">চালু</span>
                    @else
                        <span class="px-2 py-1 rounded text-xs bg-ink/5 text-mute">বন্ধ</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-mute">{{ $t->mobile_devices_count }} টি</td>
                <td class="px-4 py-3">
                    <a href="{{ route('super.remote-support.show', $t) }}" class="text-leafdk hover:underline text-xs">পরিচালনা করুন →</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-12 text-center text-mute">কোনো টেনেন্ট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
