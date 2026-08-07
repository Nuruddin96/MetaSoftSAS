@extends('layouts.panel')
@section('title', 'CSV ইমপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">CSV দিয়ে প্রোডাক্ট আপলোড</h1>

<div class="max-w-2xl space-y-6">
    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold text-sm mb-2">১. টেমপ্লেট ডাউনলোড করুন</p>
        <p class="text-sm text-mute mb-4">এক্সেলে খুলে আপনার প্রোডাক্টগুলো বসান, তারপর CSV হিসেবে সেভ করুন।</p>
        <a href="{{ route('tenant.products.template') }}" class="inline-block px-5 py-2.5 rounded-lg border border-ink/15 font-semibold text-sm hover:bg-paper">⬇ টেমপ্লেট ডাউনলোড</a>
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold text-sm mb-2">২. ফাইল আপলোড করুন</p>
        <form method="POST" action="{{ route('tenant.products.import') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".csv" required class="w-full text-sm">
            <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">আপলোড করুন</button>
        </form>
    </div>

    <div class="bg-amber/10 border border-amber/30 rounded-xl p-5 text-sm space-y-2">
        <p class="font-bold">নিয়মগুলো</p>
        <ul class="list-disc ml-5 space-y-1 text-mute">
            <li><b>name</b> আর <b>selling_price</b> — এই দুটো কলাম অবশ্যই লাগবে</li>
            <li>একই নামের একাধিক সারি দিলে সেগুলো একটাই প্রোডাক্টের আলাদা ভ্যারিয়েন্ট হবে (সাইজ/কালার)</li>
            <li>ক্যাটাগরি না থাকলে নতুন তৈরি হয়ে যাবে</li>
            <li>প্রতিটা ভ্যারিয়েন্টের বারকোড অটো জেনারেট হবে</li>
            <li>বাংলা লেখা ঠিকমতো আসতে এক্সেলে "CSV UTF-8" ফরম্যাটে সেভ করুন</li>
        </ul>
    </div>
</div>
@endsection
