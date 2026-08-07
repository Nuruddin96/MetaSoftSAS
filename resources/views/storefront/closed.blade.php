@extends('layouts.central')

@section('title', $tenant->store_name . ' — সাময়িকভাবে বন্ধ')

@section('content')
<div class="min-h-screen grid place-items-center px-4 text-center">
    <div>
        <p class="text-5xl">🔒</p>
        <h1 class="font-disp font-bold text-2xl mt-4">{{ $tenant->store_name }}</h1>
        <p class="text-mute mt-2">দোকানটি সাময়িকভাবে বন্ধ আছে। অনুগ্রহ করে কিছুক্ষণ পর আবার আসুন।</p>
    </div>
</div>
@endsection
