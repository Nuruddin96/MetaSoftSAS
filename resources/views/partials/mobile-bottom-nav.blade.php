{{--
    Mobile-only sticky bottom navigation (md:hidden). Exactly 5 items;
    Home sits in the middle as a raised, floating pill so it reads as the
    primary action without being oversized. Never rendered on the product
    detail page — that page has its own sticky "অর্ডার করুন" buy bar at the
    same screen edge, and stacking both would be exactly the kind of
    cluttered mobile UI this redesign is meant to avoid.

    $contactUrl / $contactPlatform come from layouts/store.blade.php's own
    contact-resolution @php block — reused here rather than resolved twice.
--}}
<nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-ink/10"
     style="padding-bottom: env(safe-area-inset-bottom);" aria-label="মোবাইল নেভিগেশন">
    <div class="grid grid-cols-5 items-end h-14">
        <a href="{{ route('storefront.products') }}" class="flex flex-col items-center justify-center gap-0.5 h-full text-ink/70 hover:text-brand">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
            <span class="text-[10px] leading-none">ক্যাটাগরি</span>
        </a>

        <a href="{{ route('storefront.products') }}" class="flex flex-col items-center justify-center gap-0.5 h-full text-ink/70 hover:text-brand">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 7h12l1 13H5L6 7z"/><path d="M9 7a3 3 0 016 0"/></svg>
            <span class="text-[10px] leading-none">সব প্রোডাক্ট</span>
        </a>

        {{-- Home — deliberately the only raised item, small drop shadow, still compact. --}}
        <a href="{{ route('storefront.home') }}" class="flex flex-col items-center justify-center h-full">
            <span class="-mt-5 w-12 h-12 rounded-full bg-brand text-white grid place-items-center shadow-md">
                <svg class="w-5.5 h-5.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 11.5L12 4l8 7.5"/><path d="M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/></svg>
            </span>
            <span class="text-[10px] leading-none text-ink/70 -mt-0.5">হোম</span>
        </a>

        <a href="{{ route('storefront.cart') }}" class="relative flex flex-col items-center justify-center gap-0.5 h-full text-ink/70 hover:text-brand">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h10l1 4H6l1-4z"/><path d="M5 7h14l-1.2 12.1a2 2 0 01-2 1.9H8.2a2 2 0 01-2-1.9L5 7z"/><path d="M10 11v5M14 11v5"/></svg>
            @if ($cartCount)
                <span class="absolute top-1 right-[calc(50%-16px)] bg-brand text-white text-[9px] font-bold rounded-full w-4 h-4 grid place-items-center">{{ $cartCount }}</span>
            @endif
            <span class="text-[10px] leading-none">অর্ডার</span>
        </a>

        <a href="{{ $contactUrl ?: '#' }}" @if ($contactUrl) target="_blank" rel="noopener" @endif
           class="flex flex-col items-center justify-center gap-0.5 h-full text-ink/70 hover:text-brand">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12a8 8 0 11-3.8-6.8L21 4l-1 4.5A8 8 0 0121 12z"/></svg>
            <span class="text-[10px] leading-none">মেসেজ</span>
        </a>
    </div>
</nav>
