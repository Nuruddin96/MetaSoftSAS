@extends('layouts.central')

@section('title', 'আমাদের অন্যান্য সার্ভিস — MetaSoft BD')

@section('content')
<header class="bg-paper/90 backdrop-blur border-b border-ink/10">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2">
            <x-ui.brand-mark size="sm" />
            <span class="font-disp font-bold text-lg">MetaSoft BD</span>
        </a>
        <a href="/" class="text-sm text-mute hover:text-ink">← হোমে ফিরুন</a>
    </div>
</header>

<section class="py-14 text-center px-4">
    <h1 class="font-disp font-bold text-3xl md:text-4xl">আমাদের অন্যান্য সার্ভিস</h1>
    <p class="text-mute mt-3 max-w-xl mx-auto">ওয়েবসাইট বানানো থেকে শুরু করে কনটেন্ট, মার্কেটিং, আর সম্পূর্ণ বিজনেস গ্রোথ পার্টনারশিপ — আপনার প্রয়োজন অনুযায়ী প্যাকেজ বেছে নিন।</p>
</section>

<section class="max-w-6xl mx-auto px-4 pb-16 grid md:grid-cols-2 gap-6">

    {{-- Package 1 --}}
    <div class="bg-white rounded-2xl border-2 border-leaf/30 p-7">
        <p class="text-xs font-bold text-leaf">🟢 প্যাকেজ ১</p>
        <h2 class="font-disp font-bold text-xl mt-1">WooCommerce ওয়েবসাইট ডেভেলপমেন্ট</h2>
        <p class="mt-2"><span class="font-disp font-extrabold text-2xl">২০,০০০৳</span><span class="text-mute text-sm"> ওয়ান-টাইম</span></p>
        <ul class="mt-4 space-y-1.5 text-sm text-mute">
            <li>✓ .com ডোমেইন (১ বছর) + হোস্টিং (১ বছর) + ফ্রি SSL</li>
            <li>✓ প্রিমিয়াম WooCommerce ডিজাইন, মোবাইল রেসপন্সিভ</li>
            <li>✓ প্রোডাক্ট আপলোড (৫০টি পর্যন্ত), ক্যাটাগরি সেটআপ</li>
            <li>✓ পেমেন্ট গেটওয়ে ও শিপিং সেটআপ</li>
            <li>✓ বিজনেস ইমেইল, WhatsApp চ্যাট ইন্টিগ্রেশন</li>
            <li>✓ Meta Pixel + CAPI, GA4, GTM সেটআপ</li>
            <li>✓ বেসিক SEO, সিকিউরিটি, স্পিড অপ্টিমাইজেশন</li>
            <li>✓ ৩০ দিন ফ্রি সাপোর্ট</li>
        </ul>
    </div>

    {{-- Package 2 --}}
    <div class="bg-white rounded-2xl border-2 border-amber/40 p-7">
        <p class="text-xs font-bold text-amber">🟡 প্যাকেজ ২</p>
        <h2 class="font-disp font-bold text-xl mt-1">কনটেন্ট ক্রিয়েশন</h2>
        <p class="mt-2"><span class="font-disp font-extrabold text-2xl">১৫,০০০৳</span><span class="text-mute text-sm"> /মাস</span></p>
        <ul class="mt-4 space-y-1.5 text-sm text-mute">
            <li>✓ কনটেন্ট প্ল্যানিং ও স্ট্র্যাটেজি, মাসিক কনটেন্ট ক্যালেন্ডার</li>
            <li>✓ গ্রাফিক ডিজাইন, সোশ্যাল মিডিয়া পোস্ট ডিজাইন</li>
            <li>✓ প্রোমোশনাল ভিডিও তৈরি ও এডিটিং, শর্ট ভিডিও (Reels/Shorts)</li>
            <li>✓ প্রোডাক্ট ফটোগ্রাফি</li>
            <li>✓ কপিরাইটিং ও ক্যাপশন রাইটিং</li>
        </ul>
    </div>

    {{-- Package 3 --}}
    <div class="bg-white rounded-2xl border-2 border-blue-300 p-7">
        <p class="text-xs font-bold text-blue-600">🔵 প্যাকেজ ৩</p>
        <h2 class="font-disp font-bold text-xl mt-1">ডিজিটাল মার্কেটিং</h2>
        <p class="mt-2"><span class="font-disp font-extrabold text-2xl">২০,০০০৳</span><span class="text-mute text-sm"> /মাস</span></p>
        <ul class="mt-4 space-y-1.5 text-sm text-mute">
            <li>✓ ডিজিটাল মার্কেটিং স্ট্র্যাটেজি, কম্পিটিটর ও অডিয়েন্স রিসার্চ</li>
            <li>✓ Facebook ও Instagram পেজ ম্যানেজমেন্ট</li>
            <li>✓ Meta Ads স্ট্র্যাটেজি, ক্যাম্পেইন সেটআপ ও ম্যানেজমেন্ট</li>
            <li>✓ লিড জেনারেশন, সেলস ও রিমার্কেটিং ক্যাম্পেইন</li>
            <li>✓ Pixel + সার্ভার-সাইড ট্র্যাকিং</li>
            <li>✓ Google Business Profile, GA4, GTM, Search Console</li>
            <li>✓ বেসিক ও লোকাল SEO</li>
            <li>✓ মাসিক পারফরম্যান্স রিপোর্ট ও স্ট্র্যাটেজি মিটিং</li>
        </ul>
    </div>

    {{-- Package 4 --}}
    <div class="bg-ink text-white rounded-2xl border-2 border-red-400/50 p-7">
        <p class="text-xs font-bold text-red-300">🔴 প্যাকেজ ৪ — সবচেয়ে জনপ্রিয়</p>
        <h2 class="font-disp font-bold text-xl mt-1">Business Growth Partner (অল-ইন-ওয়ান)</h2>
        <p class="mt-2"><span class="font-disp font-extrabold text-2xl">৩০,০০০৳</span><span class="text-white/70 text-sm"> /মাস</span></p>
        <ul class="mt-4 space-y-1.5 text-sm text-white/80">
            <li>🌐 সম্পূর্ণ ওয়েবসাইট ম্যানেজমেন্ট, আপডেট, হোস্টিং-সিকিউরিটি মনিটরিং</li>
            <li>🎨 কনটেন্ট প্ল্যানিং, ডিজাইন, ফটোগ্রাফি, ভিডিও এডিটিং</li>
            <li>📢 Facebook/Instagram/LinkedIn ম্যানেজমেন্ট, Meta Ads, A/B টেস্টিং</li>
            <li>🌍 Google Business, GA4, GTM, Search Console পূর্ণ ম্যানেজমেন্ট</li>
            <li>🔍 সম্পূর্ণ SEO অডিট, অন-পেজ, টেকনিক্যাল, লোকাল SEO</li>
            <li>📊 মাসিক বিজনেস গ্রোথ কনসালটেন্সি, ROI অ্যানালাইসিস</li>
            <li>🤝 প্রায়োরিটি সাপোর্ট + ডেডিকেটেড অ্যাকাউন্ট ম্যানেজার</li>
        </ul>
    </div>
</section>

<section class="max-w-4xl mx-auto px-4 pb-10">
    <div class="bg-white rounded-2xl border border-ink/10 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-paper text-left"><tr>
                <th class="px-5 py-3">প্যাকেজ</th><th class="px-5 py-3">দাম</th><th class="px-5 py-3">যাদের জন্য উপযুক্ত</th>
            </tr></thead>
            <tbody>
                <tr class="border-t border-ink/5"><td class="px-5 py-3 font-medium">প্যাকেজ ১</td><td class="px-5 py-3">২০,০০০৳ (ওয়ান-টাইম)</td><td class="px-5 py-3 text-mute">নতুন WooCommerce ওয়েবসাইট তৈরির জন্য</td></tr>
                <tr class="border-t border-ink/5"><td class="px-5 py-3 font-medium">প্যাকেজ ২</td><td class="px-5 py-3">১৫,০০০৳ /মাস</td><td class="px-5 py-3 text-mute">নিয়মিত কনটেন্ট ক্রিয়েশনের জন্য</td></tr>
                <tr class="border-t border-ink/5"><td class="px-5 py-3 font-medium">প্যাকেজ ৩</td><td class="px-5 py-3">২০,০০০৳ /মাস</td><td class="px-5 py-3 text-mute">ডিজিটাল মার্কেটিং ও লিড জেনারেশনের জন্য</td></tr>
                <tr class="border-t border-ink/5"><td class="px-5 py-3 font-medium">প্যাকেজ ৪</td><td class="px-5 py-3">৩০,০০০৳ /মাস</td><td class="px-5 py-3 text-mute">ওয়েবসাইট + কনটেন্ট + মার্কেটিং + কনসালটেন্সি — সব একসাথে</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="text-center pb-20 px-4">
    <h2 class="font-disp font-bold text-2xl">আপনার ব্যবসার জন্য কোনটা মানানসই জানতে চান?</h2>
    <p class="text-mute mt-2">সরাসরি যোগাযোগ করুন, আমরা পরামর্শ দিয়ে সাহায্য করবো</p>
    <a href="https://wa.me/8801973847204" target="_blank" class="inline-block mt-6 px-8 py-4 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        📞 WhatsApp-এ যোগাযোগ করুন
    </a>
</section>
@endsection
