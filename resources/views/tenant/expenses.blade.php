@extends('layouts.panel')
@section('title', 'খরচ')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="font-disp font-bold text-2xl">খরচ</h1>
    <div class="bg-red-50 border border-red-200 rounded-btn px-4 py-2 text-sm">এই সময়ে মোট খরচ: <b>{{ number_format($total) }}৳</b></div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <x-ui.card class="h-fit">
        <p class="font-bold text-sm mb-4">নতুন খরচ যোগ করুন</p>
        <form method="POST" action="{{ route('tenant.expenses.store') }}" class="space-y-3">
            @csrf
            <input name="title" required placeholder="খরচের বিবরণ (যেমন: দোকান ভাড়া)"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="amount" type="number" step="0.01" min="0" required placeholder="টাকার পরিমাণ"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="expense_date" type="date" required value="{{ now()->toDateString() }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="category_name" list="catList" placeholder="ক্যাটাগরি (ঐচ্ছিক)"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <datalist id="catList">
                @foreach ($categories as $c)<option value="{{ $c->name }}">@endforeach
                <option value="দোকান ভাড়া"><option value="বেতন"><option value="বিজ্ঞাপন"><option value="যাতায়াত"><option value="বিদ্যুৎ বিল">
            </datalist>
            <x-ui.button type="submit" variant="accent" size="sm" class="w-full">যোগ করুন</x-ui.button>
        </form>
    </x-ui.card>

    <div class="lg:col-span-2">
        @include('tenant.reports._filter')
        <x-ui.card padding="none" class="overflow-x-auto">
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
                                <button class="text-red-600 text-xs hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">মুছুন</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-mute">এই সময়ে কোনো খরচ নেই।</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-ui.card>
        <div class="mt-4">{{ $expenses->links() }}</div>
    </div>
</div>
@endsection
