@extends('layouts.store')

@section('title', 'সব প্রোডাক্ট — ' . $tenant->store_name)

@section('content')
<div class="flex flex-wrap items-center gap-2 mb-6">
    <a href="{{ route('storefront.products') }}"
       class="px-3 py-1.5 rounded-full text-sm {{ !request('category') ? 'bg-brand text-white' : 'bg-white border border-ink/10' }}">সব</a>
    @foreach ($categories as $cat)
        <a href="{{ route('storefront.products', ['category' => $cat->slug]) }}"
           class="px-3 py-1.5 rounded-full text-sm {{ request('category') === $cat->slug ? 'bg-brand text-white' : 'bg-white border border-ink/10' }}">{{ $cat->name }}</a>
    @endforeach
</div>

@if ($products->isEmpty())
    <p class="text-center text-mute py-20">এই ক্যাটাগরিতে কোনো প্রোডাক্ট নেই।</p>
@else
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ($products as $product)
            @include('storefront._card', ['product' => $product])
        @endforeach
    </div>
    <div class="mt-6">{{ $products->links() }}</div>
@endif
@endsection
