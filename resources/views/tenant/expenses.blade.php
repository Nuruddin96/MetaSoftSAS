@extends('layouts.panel')
@section('title', 'খরচ')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="font-disp font-bold text-2xl">খরচ</h1>
    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm">এই সময়ে মোট খরচ: <b>{{ number_format($total) }}৳</b></div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-xl border border-ink/5 p-6 h-fit">
        <p class="font-bold text-sm mb-4">নতুন খরচ যোগ করুন</p>
        <form method="POST" action="{{ route('tenant.expenses.store') }}" class="space-y-3">
            @csrf
            <input name="title" required placeholder="খরচের বিবরণ (যেমন: দোকান ভাড়া)"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="amount" type="number" step="0.01" min="0" required placeholder="টাকার পরিমাণ"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="expense_date" type="date" required value="{{ now()->toDateString() }}"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="category_name" list="catList" placeholder="ক্যাটাগরি (ঐচ্ছিক)"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <datalist id="catList">
                @foreach ($categories as $c)<option value="{{ $c->name }}">@endforeach
                <option value="দোকান ভাড়া"><option value="বেতন"><option value="বিজ্ঞাপন"><option value="যাতায়াত"><option value="বিদ্যুৎ বিল">
            </datalist>
            <button class="w-full py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">যোগ করুন</button>
        </form>
    </div>

    <div class="lg:col-span-2">
        @include('tenant.reports._filter')
        <div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                    <th class="px-4 py-3">তারিখ</th><th class="px-4 py-3">বিবরণ</th>
                    <th class="px-4 py-3">ক্যাটাগরি</th><th class="px-4 py-3">টাকা</th><th class="px-4 py-3"></th>
                </tr></thead>
                <tbody>
                @forelse ($expenses as $e)
                    <tr class="border-b border-ink/5 last:border-0">
                        <td class="px-4 py-3 text-xs text-mute">{{ $e->expense_date->format('d M Y') }}</td>
                        <td class="px-4 py-3">{{ $e->title }}</td>
                        <td class="px-4 py-3 text-mute text-xs">{{ $e->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold">{{ number_format($e->amount) }}৳</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('tenant.expenses.destroy', $e) }}" onsubmit="return confirm('মুছবেন?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-mute">এই সময়ে কোনো খরচ নেই।</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $expenses->links() }}</div>
    </div>
</div>
@endsection
