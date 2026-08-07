@extends('layouts.panel')
@section('title', 'প্রোডাক্ট সোর্স')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">প্রোডাক্ট সোর্স</h1>
        <p class="text-sm text-mute">ট্রেন্ডি পণ্য দেখুন, পছন্দ হলে অর্ডার করুন — আমরা যোগাযোগ করবো</p>
    </div>
    <a href="{{ route('tenant.product-source.orders') }}" class="text-sm text-leaf hover:underline shrink-0">আমার অর্ডারসমূহ →</a>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3 md:gap-4">
    @forelse ($products as $p)
        <a href="{{ route('tenant.product-source.show', $p) }}"
           class="bg-white rounded-lg border border-ink/5 overflow-hidden hover:shadow-md hover:border-leaf/30 transition block">
            <div class="aspect-square bg-paper grid place-items-center overflow-hidden">
                @if ($p->images->isNotEmpty())
                    <img src="{{ asset('storage/'.$p->images->first()->image_path) }}" class="w-full h-full object-cover">
                @elseif ($p->image_path)
                    <img src="{{ asset('storage/'.$p->image_path) }}" class="w-full h-full object-cover">
                @else
                    <span class="text-2xl">📦</span>
                @endif
            </div>
            <div class="p-2.5">
                <p class="text-xs leading-snug line-clamp-2 h-8">{{ $p->name }}</p>
                <p class="font-bold text-sm mt-1 text-leaf">{{ $p->priceLabel() }}৳</p>
                <p class="text-[10px] text-mute mt-0.5">
                    @if ($p->delivery_time_days)⏱ {{ $p->delivery_time_days }}@endif
                </p>
                <p class="text-[10px] text-mute">সর্বনিম্ন {{ $p->min_order_qty }}টি</p>
                <span class="block w-full mt-2 py-1.5 rounded-md bg-leaf text-white font-semibold text-[11px] text-center">বিস্তারিত দেখুন</span>
            </div>
        </a>
    @empty
        <p class="col-span-full text-center text-mute py-16">এখনো কোনো পণ্য যোগ করা হয়নি।</p>
    @endforelse
</div>
<div class="mt-6">{{ $products->links() }}</div>
@endsection
