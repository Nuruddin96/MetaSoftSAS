@extends('layouts.panel')
@section('title', 'CSV ইমপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">CSV দিয়ে প্রোডাক্ট আপলোড</h1>

<div class="max-w-2xl space-y-6">
    <x-ui.card>
        <p class="font-bold text-sm mb-2 flex items-center gap-2">
            <span class="w-5 h-5 rounded-pill bg-leaf/10 text-leafdk text-xs font-bold grid place-items-center shrink-0">১</span>
            টেমপ্লেট ডাউনলোড করুন
        </p>
        <p class="text-sm text-mute mb-4">এক্সেলে খুলে আপনার প্রোডাক্টগুলো বসান, তারপর CSV হিসেবে সেভ করুন।</p>
        <x-ui.button href="{{ route('tenant.products.template') }}" variant="outline" size="sm">
            <i data-lucide="download" class="w-4 h-4"></i> টেমপ্লেট ডাউনলোড
        </x-ui.button>
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold text-sm mb-2 flex items-center gap-2">
            <span class="w-5 h-5 rounded-pill bg-leaf/10 text-leafdk text-xs font-bold grid place-items-center shrink-0">২</span>
            ফাইল আপলোড করুন
        </p>
        <form method="POST" action="{{ route('tenant.products.import') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".csv" required class="w-full text-sm">
            <x-ui.button type="submit" variant="accent" size="sm">আপলোড করুন</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card tone="amber" class="text-sm space-y-2">
        <p class="font-bold flex items-center gap-2">
            <i data-lucide="info" class="w-4 h-4"></i> নিয়মগুলো
        </p>
        <ul class="list-disc ml-5 space-y-1 text-mute">
            <li><b>name</b> আর <b>selling_price</b> — এই দুটো কলাম অবশ্যই লাগবে</li>
            <li>একই নামের একাধিক সারি দিলে সেগুলো একটাই প্রোডাক্টের আলাদা ভ্যারিয়েন্ট হবে (সাইজ/কালার)</li>
            <li>ক্যাটাগরি না থাকলে নতুন তৈরি হয়ে যাবে</li>
            <li>প্রতিটা ভ্যারিয়েন্টের বারকোড অটো জেনারেট হবে</li>
            <li>বাংলা লেখা ঠিকমতো আসতে এক্সেলে "CSV UTF-8" ফরম্যাটে সেভ করুন</li>
        </ul>
    </x-ui.card>
</div>
@endsection
