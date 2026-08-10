@extends('layouts.central')

@section('title', 'MetaSoft BD — আপনার অনলাইন ব্যবসা, এক প্যানেলে')
@section('meta_description', 'ওয়েবসাইট, POS, ইনভেন্টরি, কুরিয়ার আর অর্ডার ম্যানেজমেন্ট — সব এক প্ল্যাটফর্মে। ৭ দিন ফ্রি ট্রায়াল, কোনো কার্ড লাগবে না। আজই আপনার অনলাইন দোকান খুলুন।')

@section('content')

{{-- ================= NAV ================= --}}
<header class="sticky top-0 z-40 bg-paper/90 backdrop-blur border-b border-ink/10">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <span class="w-8 h-8 rounded bg-leaf grid place-items-center text-white font-bold text-lg">M</span>
            <span class="font-disp font-bold text-lg">MetaSoft BD</span>
        </a>
        <nav class="hidden md:flex items-center gap-5 text-sm text-mute">
            <a href="#features" class="hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">ফিচার</a>
            <a href="#why-choose" class="hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">কেন মেটাসফট</a>
            <a href="#services" class="hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">আমাদের সার্ভিস</a>
            <a href="#pricing" class="hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">প্রাইসিং</a>
            <a href="#faq" class="hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">যা জানা দরকার</a>
        </nav>
        <div class="flex items-center gap-3">
            <a href="{{ route('affiliate.register') }}"
               class="hidden sm:inline text-sm font-semibold px-4 py-2 rounded-btn bg-amber/15 text-ink hover:bg-amber/25 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                💰 রেফার এন্ড আর্ন
            </a>
            <x-ui.button href="{{ route('central.login') }}" variant="ghost" size="sm">লগইন</x-ui.button>
            <x-ui.button href="{{ route('register') }}" variant="accent" size="sm">ফ্রি ট্রায়াল শুরু করুন</x-ui.button>
        </div>
    </div>
</header>

{{-- ================= PWA INSTALL BANNER ================= --}}
{{-- Mobile-first and prominent per spec, full-width so it can't be missed
     without competing for space in the already 2-3-button header row.
     Starts `hidden` (safe default — no flash before JS can decide) and is
     only ever revealed by pwa-install.js once it has confirmed the app
     isn't already running in standalone/installed mode; `md:hidden` keeps
     it mobile-only even after that reveal. --}}
<div id="pwaInstallBanner" class="hidden md:hidden bg-leaf/10 border-b border-leaf/20">
    <div class="max-w-6xl mx-auto px-4 py-2.5 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
            <img src="{{ asset('images/icons/icon-192.png') }}" alt="" width="32" height="32" class="w-8 h-8 rounded-lg shrink-0" aria-hidden="true">
            <p class="text-sm text-ink truncate">MetaSoft BD অ্যাপ হিসেবে ইনস্টল করুন</p>
        </div>
        <button type="button" id="pwaInstallBtn"
                class="shrink-0 flex items-center gap-1.5 text-sm font-semibold px-3.5 py-2 rounded-btn bg-leaf text-white hover:bg-leafdk active:scale-[0.97] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <i data-lucide="download" class="w-4 h-4" aria-hidden="true"></i> Install App
        </button>
    </div>
</div>

{{-- iOS Safari has no beforeinstallprompt — clicking Install App there
     shows this instruction modal instead. Hidden by default, toggled by
     pwa-install.js. --}}
<div id="pwaIosModal" class="hidden fixed inset-0 z-[999] bg-ink/50 backdrop-blur-sm items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="pwaIosModalTitle">
    <div class="bg-white rounded-card max-w-sm w-full p-6 text-center">
        <img src="{{ asset('images/icons/icon-192.png') }}" alt="" width="56" height="56" class="w-14 h-14 mx-auto rounded-xl mb-4" aria-hidden="true">
        <p id="pwaIosModalTitle" class="font-disp font-bold text-lg mb-3">MetaSoft BD ইনস্টল করুন</p>
        <p class="text-base text-ink leading-relaxed mb-5">Safari-এর Share button চাপুন → Add to Home Screen</p>
        <button type="button" id="pwaIosModalClose"
                class="w-full py-2.5 rounded-btn bg-ink text-white font-semibold text-sm hover:bg-ink/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            বুঝেছি
        </button>
    </div>
</div>

{{-- ================= HERO ================= --}}
<x-ui.section id="main-content" tabindex="-1" tone="transparent" spacing="none" class="relative overflow-hidden outline-none">
    {{-- animated background glow — decorative only, hidden from assistive tech --}}
    <div aria-hidden="true" class="absolute inset-0 -z-10 overflow-hidden">
        <div class="bg-glow absolute -top-32 -right-24 w-[28rem] h-[28rem] bg-leaf/25"></div>
        <div class="bg-glow absolute -bottom-40 -left-24 w-96 h-96 bg-amber/20" style="animation-delay: -9s"></div>
    </div>

    <x-ui.container>
        <div class="py-16 md:py-24 lg:py-28 grid lg:grid-cols-2 gap-16 items-center">
            <div>
                <x-ui.badge tone="leaf">
                    <span class="w-1.5 h-1.5 rounded-full bg-leaf"></span> ৭ দিন ফ্রি ট্রায়াল — কোনো কার্ড লাগবে না
                </x-ui.badge>

                <h1 class="mt-6 font-disp font-extrabold text-4xl sm:text-5xl lg:text-6xl leading-[1.1] text-ink">
                    আপনার ব্যবসা চালান<br>
                    <span class="text-leaf">এক প্ল্যাটফর্মে, স্বয়ংক্রিয়ভাবে।</span>
                </h1>

                <p class="mt-6 text-lg text-mute leading-relaxed max-w-lg">
                    ওয়েবসাইট, POS, ইনভেন্টরি, কুরিয়ার আর অর্ডার ম্যানেজমেন্ট — যা আগে দশটা টুল আর একটা খাতা দিয়ে সামলাতেন, এখন একটা প্ল্যাটফর্মেই।
                </p>

                <div class="mt-9 flex flex-wrap gap-4">
                    <x-ui.button href="{{ route('register') }}" variant="primary" size="lg">
                        আপনার দোকান খুলুন →
                    </x-ui.button>
                    <x-ui.button href="#features" variant="outline" size="lg">
                        কী কী পাবেন
                    </x-ui.button>
                </div>

                <p class="mt-6 text-sm text-mute">
                    আপনার সাইট হবে: <span class="font-semibold text-ink">আপনারদোকান.metasoftbd.com</span>
                </p>
            </div>

            {{-- product visual: dashboard mockup with the receipt layered in front --}}
            <div class="relative mx-auto w-full max-w-md lg:max-w-none" role="img"
                 aria-label="MetaSoft প্যানেলের একটি উদাহরণ ড্যাশবোর্ড — আজকের অর্ডার, বিক্রি ও একটি অর্ডার রিসিট দেখাচ্ছে">
                <div class="rounded-card border border-ink/10 bg-white shadow-2xl shadow-ink/10 overflow-hidden">
                    <div class="flex items-center gap-1.5 px-4 py-3 border-b border-ink/5 bg-paper/60">
                        <span class="w-2.5 h-2.5 rounded-full bg-ink/15"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-ink/15"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-ink/15"></span>
                        <span class="ml-3 text-[11px] text-mute font-medium truncate">rahimfashion.metasoftbd.com/panel</span>
                    </div>
                    <div class="p-5 sm:p-6">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-lg bg-paper/70 p-3">
                                <p class="text-[11px] text-mute">আজকের অর্ডার</p>
                                <p class="font-disp font-bold text-xl mt-1">৪৭</p>
                            </div>
                            <div class="rounded-lg bg-paper/70 p-3">
                                <p class="text-[11px] text-mute">আজকের বিক্রি</p>
                                <p class="font-disp font-bold text-xl mt-1">৫৮,৯০০৳</p>
                            </div>
                            <div class="rounded-lg bg-leaf/10 p-3">
                                <p class="text-[11px] text-leafdk">লাভ</p>
                                <p class="font-disp font-bold text-xl mt-1 text-leafdk">১৮,৪০০৳</p>
                            </div>
                        </div>

                        <div class="mt-5 space-y-2.5">
                            @foreach ([
                                ['র', 'রহিম আহমেদ', 'ORD-000217', 'ডেলিভার্ড'],
                                ['স', 'সুমাইয়া খান', 'ORD-000216', 'প্রসেসিং'],
                                ['ক', 'করিম হোসেন', 'ORD-000215', 'ডেলিভার্ড'],
                            ] as [$initial, $name, $orderNo, $status])
                                <div class="flex items-center justify-between rounded-lg border border-ink/5 px-3 py-2.5 text-sm">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span class="shrink-0 w-8 h-8 rounded-full bg-leaf/10 grid place-items-center text-leafdk text-xs font-bold">{{ $initial }}</span>
                                        <div class="min-w-0">
                                            <p class="font-medium leading-tight truncate">{{ $name }}</p>
                                            <p class="text-[11px] text-mute">{{ $orderNo }}</p>
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-xs font-semibold px-2 py-1 rounded-pill bg-leaf/10 text-leafdk">{{ $status }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- signature: thermal receipt, layered in front for depth --}}
                <div class="hidden sm:block absolute -bottom-10 -left-8 w-[250px]">
                    <div class="absolute -top-6 -left-2 bg-amber text-ink text-xs font-bold px-3 py-1.5 rounded shadow rotate-[-4deg]">
                        অর্ডার এলো 🎉
                    </div>
                    <div class="receipt-edge bg-white shadow-xl rotate-2 px-5 pt-5 pb-6 text-xs">
                        <p class="text-center font-disp font-bold text-sm">রহিম ফ্যাশন হাউজ</p>
                        <p class="text-center text-[10px] text-mute">rahimfashion.metasoftbd.com</p>
                        <div class="my-2.5 border-t border-dashed border-ink/20"></div>
                        <div class="flex justify-between"><span>অর্ডার</span><span class="font-semibold">ORD-000217</span></div>
                        <div class="flex justify-between text-mute text-[10px] mt-0.5"><span>কুমিল্লা সদর</span><span>ক্যাশ অন ডেলিভারি</span></div>
                        <div class="my-2.5 border-t border-dashed border-ink/20"></div>
                        <div class="flex justify-between"><span>পাঞ্জাবি (নেভি / L)</span><span>১,২৫০৳</span></div>
                        <div class="flex justify-between mt-1"><span>ডেলিভারি চার্জ</span><span>১২০৳</span></div>
                        <div class="flex justify-between mt-2 font-bold text-sm"><span>মোট</span><span>১,৩৭০৳</span></div>
                        <div class="my-2.5 border-t border-dashed border-ink/20"></div>
                        <div class="flex justify-between text-[10px]">
                            <span class="text-leaf font-semibold">✓ ফ্রড চেক: নিরাপদ (৯২%)</span>
                        </div>
                        <div class="barcode h-8 mt-3"></div>
                    </div>
                </div>
            </div>
        </div>
    </x-ui.container>

    <div class="barcode h-3 opacity-15"></div>
</x-ui.section>

{{-- ================= FEATURES ================= --}}
<x-ui.section id="features" tone="white">
    <x-ui.container>
        <div class="max-w-2xl">
            <x-ui.badge tone="leaf">কেন মেটাসফট</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl leading-tight">
                দশটা অ্যাপের কাজ, একটা প্ল্যাটফর্মে
            </h2>
            <p class="mt-4 text-lg text-mute leading-relaxed">
                আলাদা ওয়েবসাইট, আলাদা POS সফটওয়্যার, খাতায় বাকির হিসাব, কুরিয়ারের অ্যাপ — প্রতিটা আলাদা রাখলে ভুল হয়, সময় নষ্ট হয়। মেটাসফট একটাতেই সব একসাথে রাখে, যাতে আপনি ব্যবসাটা চালাতে পারেন, টুল ম্যানেজ করা নিয়ে ব্যস্ত না থেকে।
            </p>
        </div>

        {{-- flagship pillars — the 3 things that actually set MetaSoft apart --}}
        <div class="grid lg:grid-cols-3 gap-6 mt-12">
            <x-ui.card padding="lg" hoverable>
                <div class="w-14 h-14 rounded-xl bg-leaf/10 grid place-items-center text-leafdk">
                    <x-ui.icon name="storefront" class="w-7 h-7" />
                </div>
                <h3 class="font-bold text-xl mt-5">অনলাইনে ও দোকানে, একসাথে বিক্রি করুন</h3>
                <p class="text-mute text-sm mt-2.5 leading-relaxed">
                    সাইনআপের সাথে সাথেই নিজের সাবডোমেইনে মোবাইল-ফ্রেন্ডলি ওয়েবসাইট রেডি। দোকানে বিক্রির জন্য POS + অটো বারকোড — দুই জায়গার স্টক একই ইনভেন্টরি থেকে হিসাব হয়, আলাদা করে মেলাতে হয় না।
                </p>
                <p class="text-xs text-leafdk font-semibold mt-4">অনলাইন স্টোর + POS, একই ইনভেন্টরি</p>
            </x-ui.card>

            <x-ui.card padding="lg" hoverable>
                <div class="w-14 h-14 rounded-xl bg-leaf/10 grid place-items-center text-leafdk">
                    <x-ui.icon name="shield-check" class="w-7 h-7" />
                </div>
                <h3 class="font-bold text-xl mt-5">প্রতারণামূলক অর্ডার থেকে বাঁচুন</h3>
                <p class="text-mute text-sm mt-2.5 leading-relaxed">
                    অর্ডার কনফার্মের আগেই দেখুন এই নাম্বার আগে কতগুলো পার্সেল রিসিভ করেছে, কতগুলো ফেরত দিয়েছে। কনফার্ম হলে এক ক্লিকেই কুরিয়ারে পাঠান, সাথে সাথে কনসাইনমেন্ট আইডি ও ট্র্যাকিং কোড পাবেন।
                </p>
                <p class="text-xs text-leafdk font-semibold mt-4">Pathao, Steadfast — ইন্টিগ্রেটেড</p>
            </x-ui.card>

            <x-ui.card padding="lg" hoverable>
                <div class="w-14 h-14 rounded-xl bg-leaf/10 grid place-items-center text-leafdk">
                    <x-ui.icon name="chart-trending" class="w-7 h-7" />
                </div>
                <h3 class="font-bold text-xl mt-5">ব্যবসার আসল হিসাব হাতের কাছে</h3>
                <p class="text-mute text-sm mt-2.5 leading-relaxed">
                    কার কাছে কত বাকি — ডিজিটাল খাতায় সব হিসাব, আদায় এক ক্লিকে এন্ট্রি। কেনা দাম, বেচা দাম, খরচ মিলিয়ে মাস শেষে আসল লাভ কত, আর কোন জেলা থেকে বেশি অর্ডার আসছে — রিপোর্টে পরিষ্কার।
                </p>
                <p class="text-xs text-leafdk font-semibold mt-4">বাকির খাতা + লাভ-ক্ষতির রিপোর্ট</p>
            </x-ui.card>
        </div>

        {{-- supporting features --}}
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-6">
            @php
                $supporting = [
                    ['megaphone', 'Meta Ads রেডি', 'Pixel, Conversion API আর GTM কোড বসান — অ্যাডসের রেজাল্ট সঠিকভাবে ট্র্যাক হবে, বুস্টের টাকা কাজে লাগবে।'],
                    ['whatsapp', 'WhatsApp ইন্টিগ্রেশন', 'আপনার নাম্বার বসান, স্টোরফ্রন্টে ফ্লোটিং WhatsApp বাটন লাইভ — কাস্টমার এক ক্লিকে মেসেজ করতে পারবে।'],
                    ['warehouse', 'একাধিক ওয়্যারহাউজ', 'একাধিক গুদাম আলাদাভাবে ম্যানেজ করুন, স্টক কমে গেলে লো-স্টক অ্যালার্ট — শেষ হওয়ার আগেই টের পাবেন।'],
                    ['upload', 'CSV বাল্ক আপলোড', 'একসাথে শত শত প্রোডাক্ট CSV দিয়ে আপলোড করুন — একটা একটা করে টাইপ করার দরকার নেই।'],
                    ['globe', 'চায়না প্রোডাক্ট সোর্সিং', 'ট্রেন্ডি প্রোডাক্ট আমাদের কিউরেটেড লিস্ট থেকে দেখুন, অর্ডার করুন — আমরা সোর্সিং করে দেবো।'],
                    ['cart', 'অর্ডার রিকভারি', 'কাস্টমার নাম-নাম্বার লিখে অর্ডার শেষ করেনি? তালিকা দেখে কল করুন — হারানো সেল ফিরিয়ে আনুন।'],
                ];
            @endphp
            @foreach ($supporting as [$icon, $title, $desc])
                <x-ui.card hoverable>
                    <div class="w-11 h-11 rounded-lg bg-paper grid place-items-center text-leafdk">
                        <x-ui.icon :name="$icon" class="w-5 h-5" />
                    </div>
                    <h3 class="font-bold text-base mt-4">{{ $title }}</h3>
                    <p class="text-mute text-sm mt-2 leading-relaxed">{{ $desc }}</p>
                </x-ui.card>
            @endforeach
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= HOW IT WORKS ================= --}}
<x-ui.section tone="dark">
    <x-ui.container>
        <div class="max-w-2xl mx-auto text-center">
            <x-ui.badge tone="white">শুরু করার ধাপ</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl">৩ মিনিটে ব্যবসা অনলাইনে</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-8 mt-12">
            @php
                $steps = [
                    ['১', 'রেজিস্ট্রেশন করুন', 'দোকানের নাম আর আপনার তথ্য দিন — ব্যাস।'],
                    ['২', 'দোকান রেডি', 'সাথে সাথেই আপনার-নাম.metasoftbd.com লাইভ, সাথে অ্যাডমিন প্যানেল।'],
                    ['৩', 'প্রোডাক্ট তুলে বিক্রি শুরু', 'ছবি-দাম দিয়ে প্রোডাক্ট আপলোড করুন, লিংক শেয়ার করুন, অর্ডার নিন।'],
                ];
            @endphp
            @foreach ($steps as [$n, $t, $d])
                <div class="text-center">
                    <div class="w-14 h-14 mx-auto rounded-pill bg-amber text-ink font-disp font-extrabold text-2xl grid place-items-center">{{ $n }}</div>
                    <h3 class="font-bold text-xl mt-4">{{ $t }}</h3>
                    <p class="text-white/70 mt-2 text-sm leading-relaxed max-w-xs mx-auto">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= WHY CHOOSE METASOFT ================= --}}
<x-ui.section id="why-choose" tone="light" class="relative overflow-hidden">
    <div aria-hidden="true" class="absolute inset-0 -z-10 overflow-hidden">
        <div class="bg-glow absolute top-1/3 left-1/2 -translate-x-1/2 w-[36rem] h-[36rem] bg-leaf/10"></div>
    </div>

    <x-ui.container>
        <div class="max-w-2xl mx-auto text-center">
            <x-ui.badge tone="amber">কেন মেটাসফট বেছে নেবেন</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl leading-tight">
                ম্যানুয়ালি সামলানো বা দশটা আলাদা টুলের বদলে
            </h2>
            <p class="mt-4 text-lg text-mute leading-relaxed">
                খাতা-কলম, একাধিক অ্যাপ আর এক্সেল শিট দিয়ে ব্যবসা চালালে ভুল হয়, সময় নষ্ট হয়, আর সিদ্ধান্ত নিতে হয় আন্দাজে। মেটাসফট প্রতিটা সমস্যার সরাসরি সমাধান দেয় — অনুমান না করে, বাস্তব কারণে।
            </p>
        </div>

        {{-- three alternating feature rows — each shows a concrete visual, not a generic icon card --}}
        <div class="mt-16 space-y-16 md:space-y-24">

            {{-- Row 1: visual left, text right --}}
            <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
                <div class="order-2 lg:order-1">
                    <div class="rounded-card border border-ink/10 bg-white shadow-xl shadow-ink/5 p-2 max-w-sm mx-auto lg:mx-0">
                        <div class="rounded-[calc(var(--radius-card)-0.5rem)] bg-ink text-white p-4">
                            <p class="text-[11px] text-white/50 px-2 pb-2">আপনার প্যানেল</p>
                            @php
                                $modules = [
                                    ['storefront', 'ওয়েবসাইট'],
                                    ['cart', 'অর্ডার'],
                                    ['warehouse', 'ইনভেন্টরি'],
                                    ['shield-check', 'কাস্টমার'],
                                    ['chart-trending', 'রিপোর্ট'],
                                    ['megaphone', 'মার্কেটিং'],
                                ];
                            @endphp
                            <div class="space-y-1">
                                @foreach ($modules as [$icon, $label])
                                    <div class="flex items-center gap-3 px-2 py-2 rounded-lg {{ $loop->first ? 'bg-white/10' : '' }}">
                                        <x-ui.icon :name="$icon" class="w-4 h-4 text-white/80" />
                                        <span class="text-sm text-white/90">{{ $label }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="order-1 lg:order-2">
                    <x-ui.badge tone="leaf">একটাই লগইন</x-ui.badge>
                    <h3 class="mt-4 font-disp font-bold text-2xl md:text-3xl leading-snug">সব একসাথে, এক জায়গায়</h3>
                    <p class="mt-3 text-mute leading-relaxed">
                        ওয়েবসাইট, অর্ডার, ইনভেন্টরি, কাস্টমার, রিপোর্ট, মার্কেটিং সেটিংস — আলাদা আলাদা অ্যাকাউন্ট বা অ্যাপে লগইন করার দরকার নেই। একটা প্যানেল থেকেই পুরো ব্যবসা দেখা যায়, তাই কোনো তথ্য কোথাও হারিয়ে যায় না।
                    </p>
                </div>
            </div>

            {{-- Row 2: text left, visual right --}}
            <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
                <div>
                    <x-ui.badge tone="leaf">কম ধাপ, বেশি অর্ডার</x-ui.badge>
                    <h3 class="mt-4 font-disp font-bold text-2xl md:text-3xl leading-snug">কাস্টমারের ঝামেলা কম, আপনার সেল বেশি</h3>
                    <p class="mt-3 text-mute leading-relaxed">
                        অনেক ওয়েবসাইটে অর্ডার করতে হলে আগে অ্যাকাউন্ট বানাতে হয় — এতে অনেক কাস্টমার মাঝপথে চলে যান। মেটাসফট স্টোরফ্রন্টে কাস্টমার শুধু নাম আর ফোন নাম্বার দিয়েই অর্ডার করতে পারেন, কোনো পাসওয়ার্ড বা রেজিস্ট্রেশন ছাড়াই।
                    </p>
                </div>
                <div>
                    <div class="rounded-card border border-ink/10 bg-white shadow-xl shadow-ink/5 p-6 max-w-sm mx-auto lg:ml-auto lg:mr-0">
                        <p class="text-xs text-mute mb-4">চেকআউট</p>
                        <label class="text-xs font-medium text-mute">আপনার নাম</label>
                        <div class="mt-1 rounded-lg border border-ink/10 px-3 py-2.5 text-sm text-ink/40 bg-paper/50">রহিম আহমেদ</div>
                        <label class="text-xs font-medium text-mute mt-3 block">মোবাইল নাম্বার</label>
                        <div class="mt-1 rounded-lg border border-ink/10 px-3 py-2.5 text-sm text-ink/40 bg-paper/50">01XXXXXXXXX</div>
                        <div class="mt-4 rounded-btn bg-leaf text-white text-center text-sm font-semibold py-3">অর্ডার করুন</div>
                        <p class="text-center text-[11px] text-mute mt-3">মাত্র ২টি তথ্য — সাইনআপ লাগবে না</p>
                    </div>
                </div>
            </div>

            {{-- Row 3: visual left, text right --}}
            <div class="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
                <div class="order-2 lg:order-1">
                    <div class="rounded-card border border-ink/10 bg-white shadow-xl shadow-ink/5 p-6 max-w-sm mx-auto lg:mx-0">
                        <p class="text-xs text-mute">সাপ্তাহিক বিক্রি</p>
                        <div class="mt-4 flex items-end gap-2.5 h-28">
                            @foreach ([40, 65, 50, 80, 60, 95, 70] as $h)
                                <div class="flex-1 rounded-t-md {{ $loop->iteration === 6 ? 'bg-leaf' : 'bg-leaf/20' }}" style="height: {{ $h }}%"></div>
                            @endforeach
                        </div>
                        <div class="flex justify-between mt-2 text-[10px] text-mute">
                            <span>শনি</span><span>রবি</span><span>সোম</span><span>মঙ্গল</span><span>বুধ</span><span>বৃহঃ</span><span>শুক্র</span>
                        </div>
                    </div>
                </div>
                <div class="order-1 lg:order-2">
                    <x-ui.badge tone="leaf">অনুমান না করে, দেখে সিদ্ধান্ত নিন</x-ui.badge>
                    <h3 class="mt-4 font-disp font-bold text-2xl md:text-3xl leading-snug">ব্যবসার আসল অবস্থা রিপোর্টে</h3>
                    <p class="mt-3 text-mute leading-relaxed">
                        কোন দিন বেশি বিক্রি হয়, কোন প্রোডাক্ট সবচেয়ে লাভজনক, কোন জেলা থেকে বেশি অর্ডার আসে — সব রিপোর্টে পরিষ্কার দেখা যায়। ধারণার ওপর না, সংখ্যা দেখে সিদ্ধান্ত নেওয়া যায়।
                    </p>
                </div>
            </div>
        </div>

        {{-- supporting benefits — compact strip, deliberately not a uniform 3-col card grid --}}
        <div class="mt-16 md:mt-20 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-px bg-ink/5 rounded-card overflow-hidden border border-ink/5">
            @php
                $benefits = [
                    ['upload', 'বারবার একই কাজ নয়', 'বারকোড, অর্ডার নাম্বার, স্টক আপডেট — নিজে থেকেই হয়ে যায়।'],
                    ['chart-trending', 'ছোট থেকে শুরু, বড় হওয়ার সুযোগ', 'প্ল্যান বদলে যান, একই প্যানেল — নতুন করে সেটআপ লাগে না।'],
                    ['shield-check', 'আপনার ডেটা সুরক্ষিত', 'কুরিয়ার ও মার্কেটিং API-কি এনক্রিপ্টেড থাকে, প্রতিটা দোকানের ডেটা আলাদা।'],
                    ['globe', 'বাংলাদেশের ব্যবসার জন্যই', 'বাংলা ইন্টারফেস, বাকির খাতা, দেশীয় কুরিয়ার — সবকিছু স্থানীয় বাস্তবতা মাথায় রেখে।'],
                    ['cart', 'মোবাইল থেকেও চালান', 'ফোন, ট্যাব বা ল্যাপটপ — যেকোনো ডিভাইস থেকে প্যানেল ব্যবহার করা যায়।'],
                ];
            @endphp
            @foreach ($benefits as [$icon, $title, $desc])
                <div class="bg-white p-6 hover:bg-paper/40 transition">
                    <x-ui.icon :name="$icon" class="w-5 h-5 text-leafdk" />
                    <h4 class="font-bold text-sm mt-3">{{ $title }}</h4>
                    <p class="text-mute text-xs mt-2 leading-relaxed">{{ $desc }}</p>
                </div>
            @endforeach
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= COMPANY / SERVICES ================= --}}
<x-ui.section id="services" tone="light">
    <x-ui.container>
        <div class="grid md:grid-cols-2 gap-12 items-center">
            <div>
                <x-ui.badge tone="leaf">আমাদের সম্পর্কে</x-ui.badge>
                <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl">MetaSoft BD সম্পর্কে</h2>
                <p class="text-mute mt-4 leading-relaxed">
                    আমরা বাংলাদেশের ছোট ও মাঝারি ব্যবসার জন্য সফটওয়্যার বানাই। হাজারো সেলার এখনো খাতা-কলম আর
                    মেসেঞ্জারে অর্ডার সামলান — আমাদের লক্ষ্য, প্রযুক্তিকে তাদের হাতের নাগালে আনা, সহজ বাংলায়।
                </p>
                <ul class="mt-6 space-y-3 text-sm">
                    <li class="flex items-start gap-3">
                        <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                        বিজনেস অটোমেশন সফটওয়্যার (এই প্ল্যাটফর্ম)
                    </li>
                    <li class="flex items-start gap-3">
                        <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                        কাস্টম ওয়েবসাইট ও সফটওয়্যার ডেভেলপমেন্ট
                    </li>
                    <li class="flex items-start gap-3">
                        <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                        ডিজিটাল মার্কেটিং ও Meta Ads সাপোর্ট
                    </li>
                    <li class="flex items-start gap-3">
                        <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                        ব্যবসার জন্য টেকনিক্যাল পরামর্শ ও ট্রেনিং
                    </li>
                </ul>
            </div>
            <div class="grid grid-cols-2 gap-4">
                {{--
                    TODO: real company photos go in public/images/company/
                    (company-1.jpg .. company-4.jpg). Each one is only
                    rendered if the file actually exists on disk — until
                    then a clean placeholder shows instead of a broken
                    image icon. Once the real files are added, they start
                    rendering automatically on the next page load; no code
                    change needed. Alt text below is provisional (written
                    from the section's context, not from having seen the
                    actual images) — review once the real photos are in.
                --}}
                @php
                    $companyPhotos = [
                        ['company-1.jpg', 'MetaSoft BD টিমের কর্মক্ষেত্রের একটি মুহূর্ত'],
                        ['company-2.jpg', 'MetaSoft BD অফিসে টিম মিটিং'],
                        ['company-3.jpg', 'MetaSoft BD টিমের একজন সদস্য কাজ করছেন'],
                        ['company-4.jpg', 'MetaSoft BD-এর কর্মপরিবেশ'],
                    ];
                @endphp
                @foreach ($companyPhotos as [$file, $alt])
                    @php $companyPhotoPath = public_path('images/company/' . $file); @endphp
                    <div class="aspect-[4/3] rounded-card overflow-hidden {{ $loop->iteration % 2 === 0 ? 'mt-6' : '' }}">
                        @if (file_exists($companyPhotoPath))
                            <img
                                src="{{ asset('images/company/' . $file) }}"
                                alt="{{ $alt }}"
                                loading="lazy"
                                class="w-full h-full object-cover object-center hover:scale-105 transition-transform duration-300"
                            >
                        @else
                            <div class="w-full h-full bg-paper border border-ink/10 grid place-items-center gap-2 text-mute/70">
                                <x-ui.icon name="image" class="w-8 h-8" />
                                <span class="text-xs font-medium">ছবি শীঘ্রই যুক্ত হবে</span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= REFER & EARN ================= --}}
<x-ui.section tone="transparent" class="bg-gradient-to-br from-amber/10 to-leaf/5">
    <x-ui.container size="narrow">
        <div class="text-center">
            <x-ui.badge tone="amber">রেফারেল প্রোগ্রাম</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl">রেফার করুন, আয় করুন</h2>
            <p class="mt-3 text-mute max-w-lg mx-auto">কোনো বিনিয়োগ ছাড়াই — শুধু শেয়ার করুন আর কেউ যোগ দিলে আয় করুন।</p>
        </div>

        <div class="grid md:grid-cols-2 gap-6 mt-10">
            <x-ui.card padding="lg" hoverable>
                <div class="w-12 h-12 rounded-xl bg-leaf/10 grid place-items-center text-leafdk">
                    <x-ui.icon name="storefront" class="w-6 h-6" />
                </div>
                <h3 class="font-bold text-lg mt-4">SaaS দোকান রেফার করুন</h3>
                <p class="text-mute text-sm mt-2 leading-relaxed">
                    কেউ আপনার লিংকে দোকান খুলে প্রথম পেমেন্ট করলেই পান প্রথম পেমেন্টের <b class="text-ink">২০%</b> — ওয়ান-টাইম বোনাস।
                </p>
            </x-ui.card>
            <x-ui.card padding="lg" hoverable>
                <div class="w-12 h-12 rounded-xl bg-leaf/10 grid place-items-center text-leafdk">
                    <x-ui.icon name="rocket" class="w-6 h-6" />
                </div>
                <h3 class="font-bold text-lg mt-4">সার্ভিস ক্লায়েন্ট রেফার করুন</h3>
                <p class="text-mute text-sm mt-2 leading-relaxed">
                    আমাদের মাসিক সার্ভিস প্যাকেজে ক্লায়েন্ট এনে দিন — পান <b class="text-ink">১০০০৳ প্রতি মাসে</b>, যতদিন ক্লায়েন্ট চালু থাকবে — লাইফটাইম।
                </p>
            </x-ui.card>
        </div>

        <div class="mt-8 text-center">
            <x-ui.button href="{{ route('affiliate.register') }}" variant="amber" size="lg">
                অ্যাফিলিয়েট হিসেবে যোগ দিন
            </x-ui.button>
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= PRICING ================= --}}
<x-ui.section id="pricing" tone="white" class="border-y border-ink/5">
    <x-ui.container size="narrow">
        <div class="text-center">
            <x-ui.badge tone="leaf">প্রাইসিং</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl">সহজ, স্বচ্ছ প্রাইসিং</h2>
            <p class="mt-3 text-mute">সব প্ল্যানেই ৭ দিন ফ্রি ট্রায়াল। যেকোনো সময় প্ল্যান বদলাতে পারবেন।</p>
        </div>

        {{-- monthly/yearly toggle — same price fields billing.blade.php already uses (price_monthly / price_yearly),
             this only changes which one is displayed here; registration/billing logic is untouched --}}
        <div class="mt-8 flex justify-center">
            <div class="inline-flex items-center gap-1 p-1 rounded-pill bg-paper border border-ink/10">
                <button type="button" data-cycle="monthly" class="cycle-btn active px-4 py-2 rounded-pill text-sm font-semibold transition bg-white shadow-sm text-ink">
                    মাসিক
                </button>
                <button type="button" data-cycle="yearly" class="cycle-btn px-4 py-2 rounded-pill text-sm font-semibold transition text-mute">
                    বাৎসরিক
                </button>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-6 mt-10">
            @foreach ($plans as $plan)
                @php
                    $isPopular = $loop->iteration === 2;
                    $yearlySavingsPct = $plan->price_monthly > 0
                        ? (int) round((1 - ($plan->price_yearly / ($plan->price_monthly * 12))) * 100)
                        : 0;
                @endphp
                <x-ui.card
                    padding="lg"
                    hoverable
                    :class="$isPopular
                        ? 'relative flex flex-col border-leaf ring-2 ring-leaf/20 bg-gradient-to-b from-leaf/5 to-white'
                        : 'relative flex flex-col'"
                >
                    @if ($isPopular)
                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-leaf text-white text-xs font-bold px-3 py-1 rounded-pill">
                            জনপ্রিয়
                        </span>
                    @endif

                    <h3 class="font-bold text-lg">{{ $plan->name }}</h3>

                    <div class="mt-3">
                        <p data-cycle-price="monthly">
                            <span class="font-disp font-extrabold text-4xl">{{ number_format($plan->price_monthly) }}৳</span>
                            <span class="text-mute text-sm">/মাস</span>
                        </p>
                        <p data-cycle-price="yearly" class="hidden">
                            <span class="font-disp font-extrabold text-4xl">{{ number_format($plan->price_yearly) }}৳</span>
                            <span class="text-mute text-sm">/বছর</span>
                            @if ($yearlySavingsPct > 0)
                                <span class="ml-1 inline-block text-xs font-semibold text-leafdk bg-leaf/10 px-2 py-0.5 rounded-pill align-middle">
                                    {{ $yearlySavingsPct }}% সেভ
                                </span>
                            @endif
                        </p>
                    </div>

                    <ul class="mt-6 space-y-3 text-sm text-mute flex-1">
                        <li class="flex items-start gap-2.5">
                            <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                            {{ $plan->max_products ? number_format($plan->max_products) . 'টি প্রোডাক্ট' : 'আনলিমিটেড প্রোডাক্ট' }}
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                            {{ $plan->max_staff ? $plan->max_staff . ' জন স্টাফ' : 'আনলিমিটেড স্টাফ' }}
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                            {{ $plan->max_warehouses ? $plan->max_warehouses . 'টি ওয়্যারহাউজ' : 'আনলিমিটেড ওয়্যারহাউজ' }}
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-ui.icon name="shield-check" class="w-4.5 h-4.5 text-leaf shrink-0 mt-0.5" />
                            কুরিয়ার, ফ্রড চেকার
                        </li>
                        <li class="flex items-start gap-2.5 {{ ! $plan->allow_pos ? 'opacity-50' : '' }}">
                            <x-ui.icon name="{{ $plan->allow_pos ? 'shield-check' : 'cart' }}" class="w-4.5 h-4.5 {{ $plan->allow_pos ? 'text-leaf' : 'text-mute' }} shrink-0 mt-0.5" />
                            {{ $plan->allow_pos ? 'POS বিক্রি' : 'POS নেই' }}
                        </li>
                        <li class="flex items-start gap-2.5 {{ ! $plan->allow_custom_domain ? 'opacity-50' : '' }}">
                            <x-ui.icon name="{{ $plan->allow_custom_domain ? 'shield-check' : 'globe' }}" class="w-4.5 h-4.5 {{ $plan->allow_custom_domain ? 'text-leaf' : 'text-mute' }} shrink-0 mt-0.5" />
                            {{ $plan->allow_custom_domain ? 'নিজের ডোমেইন (myshop.com)' : 'কাস্টম ডোমেইন নেই' }}
                        </li>
                    </ul>

                    <x-ui.button
                        href="{{ route('register') }}"
                        :variant="$isPopular ? 'accent' : 'outline'"
                        class="mt-6 w-full"
                    >
                        ট্রায়াল শুরু করুন
                    </x-ui.button>
                </x-ui.card>
            @endforeach
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= FAQ ================= --}}
{{-- Native <details>/<summary> — accessible and keyboard-operable by default,
     no JS/ARIA wiring needed. Every answer below is verified against actual
     app behavior (RegisterController, CheckSubscription, CheckoutController,
     CourierManager, SuperAdmin\TenantController), nothing invented. --}}
<x-ui.section id="faq" tone="white">
    <x-ui.container size="narrow">
        <div class="text-center">
            <x-ui.badge tone="amber">শুরু করার আগে</x-ui.badge>
            <h2 class="mt-4 font-disp font-bold text-3xl md:text-4xl">যা জানা দরকার</h2>
            <p class="mt-3 text-mute">সবচেয়ে বেশি জিজ্ঞাসিত প্রশ্নগুলোর সরাসরি উত্তর</p>
        </div>

        <div class="mt-10 divide-y divide-ink/10 border-t border-b border-ink/10">
            @php
                $faqs = [
                    [
                        'ট্রায়াল শেষে কি আমার কার্ড থেকে অটোমেটিক টাকা কেটে নেবে?',
                        'না। রেজিস্ট্রেশনের সময় কোনো কার্ডের তথ্যই নেওয়া হয় না, তাই অটো চার্জ হওয়ার প্রশ্নই নেই। ৭ দিনের ট্রায়াল শেষ হলে আপনি নিজে বিলিং পেজ থেকে প্ল্যান বেছে পেমেন্ট করবেন — না করলে স্টোর সাময়িকভাবে বন্ধ থাকবে, কোনো টাকা কাটা হবে না।',
                    ],
                    [
                        'আমার টেকনিক্যাল জ্ঞান নেই, তারপরও কি ব্যবহার করতে পারবো?',
                        'হ্যাঁ। পুরো প্যানেল বাংলায়, আর রেজিস্ট্রেশন করলেই সাথে সাথে আপনার সাবডোমেইনে ওয়েবসাইট রেডি হয়ে যায় — কোনো কোডিং লাগে না। লোগো, ব্যানার, পেজ — সবকিছু সাধারণ ফর্ম দিয়েই সেট করা যায়।',
                    ],
                    [
                        'আমার কাস্টমাররা কীভাবে পেমেন্ট করবে?',
                        'এখন ক্যাশ অন ডেলিভারি (COD) সাপোর্ট করে, যেটা বাংলাদেশে বেশিরভাগ অনলাইন অর্ডারের স্বাভাবিক পদ্ধতি। কাস্টমার অর্ডার কনফার্ম করবেন, পণ্য হাতে পেয়ে টাকা দেবেন।',
                    ],
                    [
                        'কাস্টমারকে কি আমার সাইটে অ্যাকাউন্ট বানাতে হবে?',
                        'না। কাস্টমার শুধু নাম আর মোবাইল নাম্বার দিয়েই অর্ডার করতে পারবেন — কোনো পাসওয়ার্ড বা সাইনআপ প্রক্রিয়া নেই। যত কম ধাপ, তত কম কাস্টমার মাঝপথে চলে যাওয়ার সম্ভাবনা।',
                    ],
                    [
                        'কুরিয়ার আর ফ্রড চেকার সেটআপ করা কি জটিল?',
                        'না। আপনার Steadfast বা Pathao অ্যাকাউন্টের API Key সেটিংস পেজে বসিয়ে দিলেই হয়ে যায় — আলাদা কোনো ইন্টিগ্রেশন প্রজেক্ট লাগে না। এরপর ফ্রড চেকার নিজে থেকেই ওই কুরিয়ারের রিয়েল ডেলিভারি হিস্ট্রি দেখে রেজাল্ট দেবে।',
                    ],
                    [
                        'পরে প্ল্যান আপগ্রেড বা ডাউনগ্রেড করতে পারবো?',
                        'হ্যাঁ, বিলিং পেজ থেকে যেকোনো সময়। একই প্যানেল, একই ডেটা — প্ল্যান বদলালে নতুন করে সেটআপ করা লাগে না।',
                    ],
                    [
                        'নিজের ডোমেইন (যেমন myshop.com) ব্যবহার করতে পারবো?',
                        'Pro প্ল্যানে হ্যাঁ। সেটিংস থেকে ডোমেইন রিকোয়েস্ট করবেন, আমরা যাচাই করে অ্যাক্টিভেট করে দেব — এটা একটা সংক্ষিপ্ত ম্যানুয়াল ধাপ, তাই সাথে সাথে না হয়ে সাধারণত অল্প সময়ের মধ্যে চালু হয়।',
                    ],
                ];
            @endphp
            @foreach ($faqs as [$question, $answer])
                <details class="group py-5" @if ($loop->first) open @endif>
                    <summary class="flex items-center justify-between gap-4 cursor-pointer list-none marker:hidden [&::-webkit-details-marker]:hidden">
                        <span class="font-semibold text-ink">{{ $question }}</span>
                        <x-ui.icon name="chevron-down" class="w-5 h-5 shrink-0 text-mute transition-transform duration-200 group-open:rotate-180" />
                    </summary>
                    <p class="mt-3 text-mute leading-relaxed pr-8">{{ $answer }}</p>
                </details>
            @endforeach
        </div>

        <p class="text-center text-sm text-mute mt-8">
            আরও প্রশ্ন আছে?
            <a href="https://wa.me/{{ preg_replace('/\D/', '', config('payment.support_whatsapp')) }}"
               target="_blank" rel="noopener" class="text-leaf font-semibold hover:underline">
                WhatsApp-এ সরাসরি জিজ্ঞাসা করুন
            </a>
        </p>
    </x-ui.container>
</x-ui.section>

{{-- ================= OTHER SERVICES ================= --}}
<x-ui.section tone="dark" spacing="compact">
    <x-ui.container size="narrow" class="text-center">
        <h2 class="font-disp font-bold text-2xl md:text-3xl">শুধু ওয়েবসাইট না — সম্পূর্ণ ডিজিটাল সমাধান</h2>
        <p class="text-white/70 mt-3 max-w-lg mx-auto">
            ওয়েবসাইট ডেভেলপমেন্ট, কনটেন্ট ক্রিয়েশন, ডিজিটাল মার্কেটিং — আমাদের এজেন্সি সার্ভিসগুলোও দেখুন
        </p>
        <div class="mt-6">
            <x-ui.button href="{{ route('services') }}" variant="white">
                অন্যান্য সার্ভিস দেখুন
            </x-ui.button>
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= FINAL CTA ================= --}}
<x-ui.section tone="transparent" spacing="none" class="relative overflow-hidden">
    <div aria-hidden="true" class="absolute inset-0 -z-10 overflow-hidden">
        <div class="bg-glow absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[40rem] h-[40rem] bg-leaf/15"></div>
    </div>
    <x-ui.container size="narrow">
        <div class="py-20 md:py-28 text-center">
            <h2 class="font-disp font-bold text-3xl md:text-5xl leading-tight">আজই আপনার দোকান খুলুন</h2>
            <p class="mt-4 text-lg text-mute">৭ দিন সম্পূর্ণ ফ্রি, কোনো কার্ড লাগবে না। পছন্দ না হলে কিছুই দিতে হবে না।</p>
            <div class="mt-8">
                <x-ui.button href="{{ route('register') }}" variant="accent" size="lg">
                    ফ্রি ট্রায়াল শুরু করুন →
                </x-ui.button>
            </div>
        </div>
    </x-ui.container>
</x-ui.section>

{{-- ================= FOOTER ================= --}}
<footer class="bg-ink text-white/70 text-sm">
    <div class="barcode h-2 opacity-30 invert"></div>
    <x-ui.container>
        <div class="py-12 grid md:grid-cols-3 gap-10">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-8 h-8 rounded bg-leaf grid place-items-center text-white font-bold text-lg">M</span>
                    <span class="font-disp font-bold text-white text-lg">MetaSoft BD</span>
                </div>
                <p class="mt-3 max-w-xs leading-relaxed">বাংলাদেশের ব্যবসার জন্য বিজনেস অটোমেশন প্ল্যাটফর্ম।</p>
            </div>
            <div>
                <p class="text-white font-semibold mb-3">লিংক</p>
                <nav class="space-y-2" aria-label="ফুটার নেভিগেশন">
                    <a href="#features" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">ফিচার</a>
                    <a href="#why-choose" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">কেন মেটাসফট</a>
                    <a href="#pricing" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">প্রাইসিং</a>
                    <a href="#faq" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">যা জানা দরকার</a>
                    <a href="{{ route('register') }}" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">রেজিস্ট্রেশন</a>
                </nav>
            </div>
            <div>
                <p class="text-white font-semibold mb-3">যোগাযোগ</p>
                <div class="space-y-2">
                    <p>ঢাকা, বাংলাদেশ</p>
                    <a href="mailto:support@metasoftbd.com" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">support@metasoftbd.com</a>
                    <a href="https://wa.me/{{ preg_replace('/\D/', '', config('payment.support_whatsapp')) }}"
                       target="_blank" rel="noopener" class="block hover:text-white transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                        WhatsApp-এ মেসেজ করুন
                    </a>
                </div>
            </div>
        </div>
    </x-ui.container>
    <div class="border-t border-white/10 py-4 text-center text-xs">© {{ date('Y') }} MetaSoft BD — সর্বস্বত্ব সংরক্ষিত</div>
</footer>

<script>
    // Pricing monthly/yearly toggle — display only, no form submission here.
    // The actual billing cycle is chosen later on the real billing page
    // (resources/views/tenant/billing.blade.php), which already posts
    // cycle=monthly|yearly to BillingController — this toggle doesn't touch that.
    document.querySelectorAll('.cycle-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cycle = btn.dataset.cycle;

            document.querySelectorAll('.cycle-btn').forEach((b) => {
                const isActive = b === btn;
                b.classList.toggle('bg-white', isActive);
                b.classList.toggle('shadow-sm', isActive);
                b.classList.toggle('text-ink', isActive);
                b.classList.toggle('text-mute', !isActive);
            });

            document.querySelectorAll('[data-cycle-price]').forEach((el) => {
                el.classList.toggle('hidden', el.dataset.cyclePrice !== cycle);
            });
        });
    });
</script>

@endsection
