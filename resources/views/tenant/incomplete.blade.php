@extends('layouts.panel')
@section('title', 'অসম্পূর্ণ অর্ডার')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">অসম্পূর্ণ অর্ডার</h1>
<p class="text-sm text-mute mb-6">যারা চেকআউটে নাম-নাম্বার লিখেও অর্ডার শেষ করেনি। কল করে অর্ডার কনফার্ম করান — হারানো বিক্রি ফিরিয়ে আনুন।</p>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">কাস্টমার</th><th class="px-4 py-3">কার্টে যা ছিল</th>
            <th class="px-4 py-3">সময়</th><th class="px-4 py-3">স্ট্যাটাস</th>
        </tr></thead>
        <tbody>
        @forelse ($items as $item)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3">
                    <p class="font-medium">{{ $item->customer_name ?: '—' }}</p>
                    <a href="tel:{{ $item->customer_phone }}" class="text-leaf text-xs font-medium">📞 {{ $item->customer_phone }}</a>
                    @if ($item->customer_address)<p class="text-xs text-mute mt-0.5">{{ Str::limit($item->customer_address, 40) }}</p>@endif
                </td>
                <td class="px-4 py-3 text-xs text-mute">
                    @foreach (($item->cart_json ?? []) as $vid => $qty)
                        <div>{{ $variants[$vid]->product->name ?? 'প্রোডাক্ট' }} × {{ $qty }}</div>
                    @endforeach
                </td>
                <td class="px-4 py-3 text-xs text-mute">{{ $item->last_activity_at?->diffForHumans() }}</td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('tenant.incomplete.status', $item) }}">
                            @csrf
                            <select name="status" onchange="this.form.submit()" class="rounded border border-ink/15 px-2 py-1 text-xs bg-white">
                                @foreach (['abandoned' => 'নতুন', 'contacted' => 'কল করা হয়েছে', 'discarded' => 'বাদ'] as $k => $v)
                                    <option value="{{ $k }}" @selected($item->status === $k)>{{ $v }}</option>
                                @endforeach
                            </select>
                        </form>
                        <form method="POST" action="{{ route('tenant.incomplete.destroy', $item) }}" onsubmit="return confirm('এন্ট্রিটি মুছবেন?')">
                            @csrf @method('DELETE')
                            <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-12 text-center text-mute">কোনো অসম্পূর্ণ অর্ডার নেই। 🎉</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
