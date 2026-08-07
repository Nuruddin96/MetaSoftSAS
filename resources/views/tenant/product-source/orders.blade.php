@extends('layouts.panel')
@section('title', 'আমার সোর্সিং অর্ডার')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">আমার সোর্সিং অর্ডার</h1>
    <a href="{{ route('tenant.product-source.index') }}" class="text-sm text-leaf hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← পণ্য দেখুন</a>
</div>

<x-ui.card padding="none" class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">পণ্য</th><th class="px-4 py-3">পরিমাণ</th>
            <th class="px-4 py-3">স্ট্যাটাস</th><th class="px-4 py-3">অ্যাডমিন নোট</th><th class="px-4 py-3">তারিখ</th>
        </tr></thead>
        <tbody>
        @forelse ($orders as $o)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3">{{ $o->product?->name }}</td>
                <td class="px-4 py-3">{{ $o->quantity }}</td>
                <td class="px-4 py-3">
                    <span class="px-2.5 py-1 rounded-pill text-xs font-semibold {{ $o->status === 'delivered' ? 'bg-leaf/10 text-leafdk' : ($o->status === 'cancelled' ? 'bg-red-50 text-red-600' : 'bg-amber/15 text-ink') }}">
                        {{ ['pending' => 'পেন্ডিং', 'contacted' => 'যোগাযোগ হয়েছে', 'confirmed' => 'কনফার্মড', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'বাতিল'][$o->status] }}
                    </span>
                </td>
                <td class="px-4 py-3 text-mute text-xs">{{ $o->admin_note ?: '—' }}</td>
                <td class="px-4 py-3 text-xs text-mute">{{ $o->created_at->format('d M Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-14 text-center text-mute">
                <i data-lucide="package-search" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                কোনো অর্ডার নেই।
            </td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
