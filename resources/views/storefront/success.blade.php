@extends('layouts.store')

@section('title', 'অর্ডার সফল — ' . $tenant->store_name)

@section('content')
<div class="max-w-md mx-auto text-center py-10">
    <p class="text-6xl">✅</p>
    <h1 class="font-disp font-bold text-2xl mt-4">অর্ডার কনফার্মড!</h1>
    <p class="text-mute mt-2">অর্ডার নাম্বার: <b class="text-ink">{{ $order->order_number }}</b></p>
    <p class="text-sm text-mute mt-1">আমরা শিগগিরই ফোনে যোগাযোগ করবো।</p>

    <div class="bg-white rounded-xl border border-ink/5 p-5 mt-6 text-left text-sm">
        @foreach ($order->items as $item)
            <div class="flex justify-between py-1">
                <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                <span>{{ number_format($item->line_total) }}৳</span>
            </div>
        @endforeach
        <div class="flex justify-between py-1 text-mute">
            <span>ডেলিভারি চার্জ</span><span>{{ number_format($order->delivery_charge) }}৳</span>
        </div>
        <div class="flex justify-between pt-2 mt-1 border-t border-dashed border-ink/15 font-bold text-base">
            <span>মোট (ক্যাশ অন ডেলিভারি)</span><span>{{ number_format($order->total) }}৳</span>
        </div>
    </div>

    <a href="{{ route('storefront.products') }}" class="inline-block mt-6 text-brand font-semibold hover:underline">← আরও কেনাকাটা করুন</a>
</div>

@push('scripts')
<script>
    if (typeof fbq === 'function') {
        fbq('track', 'Purchase', {
            currency: 'BDT',
            value: {{ (float) $order->total }},
            contents: @json($order->items->map(fn ($i) => ['id' => $i->sku, 'quantity' => $i->quantity])),
            content_type: 'product',
        }, { eventID: @json($order->fb_event_id) });
    }
</script>
@endpush
@endsection