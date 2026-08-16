@extends('layouts.super')
@section('title', 'AI ক্রেডিট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">🤖 AI এজেন্ট ক্রেডিট</h1>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="স্টোরের নাম..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-72">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব</option>
        <option value="has_credit" @selected(request('status') === 'has_credit')>ক্রেডিট আছে</option>
        <option value="exhausted" @selected(request('status') === 'exhausted')>ক্রেডিট শেষ / বরাদ্দ হয়নি</option>
        <option value="paused" @selected(request('status') === 'paused')>প্ল্যাটফর্ম থেকে পজ করা</option>
    </select>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">টেনেন্ট</th>
            <th class="px-4 py-3">প্ল্যান</th>
            <th class="px-4 py-3">ব্যালেন্স</th>
            <th class="px-4 py-3">AI Agent</th>
        </tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer" onclick="window.location='{{ route('super.ai-credit.show', $t) }}'">
                <td class="px-4 py-3 font-medium">{{ $t->store_name }}</td>
                <td class="px-4 py-3 text-mute text-xs">{{ $t->plan?->name }}</td>
                <td class="px-4 py-3">
                    @if (! $t->aiCreditAccount)
                        <span class="px-2 py-1 rounded text-xs bg-ink/5 text-mute">বরাদ্দ হয়নি</span>
                    @elseif ($t->aiCreditAccount->balance <= 0)
                        <span class="px-2 py-1 rounded text-xs bg-red-50 text-red-600 font-semibold">০ (শেষ)</span>
                    @else
                        <span class="font-semibold">{{ number_format($t->aiCreditAccount->balance, 2) }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if ($t->isAiPaused())
                        <span class="px-2 py-1 rounded text-xs bg-red-50 text-red-600 font-semibold">⛔ পজড</span>
                    @endif
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
