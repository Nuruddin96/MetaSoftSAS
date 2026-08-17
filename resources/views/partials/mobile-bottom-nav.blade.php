{{--
    Mobile-only sticky bottom navigation (md:hidden) — same fixed/height/
    safe-area convention as the tenant panel's own mobile tab bar
    (layouts/panel.blade.php), reusing the same Lucide icon set rather than
    a second icon system. Background is this tenant's own theme color
    (--color-brand, set per-tenant in this same layout's <head>), not a
    hardcoded value — a different tenant's storefront gets a different bar
    color automatically.

    $contactUrl comes from layouts/store.blade.php's own contact-resolution
    @php block — reused here rather than resolved twice.
--}}
<nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-brand flex items-end justify-around pt-1.5 pb-[calc(0.375rem+env(safe-area-inset-bottom))]" aria-label="মোবাইল নেভিগেশন">
    <a href="{{ route('storefront.products') }}" class="flex flex-col items-center gap-1 px-2 py-1 text-white/75 hover:text-white">
        <i data-lucide="layout-grid" class="w-5 h-5"></i>
        <span class="text-[10px] font-medium leading-none">ক্যাটাগরি</span>
    </a>

    <a href="{{ route('storefront.products') }}" class="flex flex-col items-center gap-1 px-2 py-1 {{ request()->routeIs('storefront.products') ? 'text-white' : 'text-white/75' }} hover:text-white">
        <i data-lucide="shopping-bag" class="w-5 h-5"></i>
        <span class="text-[10px] font-medium leading-none">সব প্রোডাক্ট</span>
    </a>

    {{-- Home — the one visually different item: centered, slightly larger icon, raised on its own soft white capsule with a subtle shadow. Still compact, not a giant floating circle. --}}
    <a href="{{ route('storefront.home') }}" class="flex flex-col items-center gap-1 px-2">
        <span class="-mt-4 w-11 h-11 rounded-2xl bg-white text-brand grid place-items-center shadow-md">
            <i data-lucide="home" class="w-6 h-6"></i>
        </span>
        <span class="text-[10px] font-medium leading-none {{ request()->routeIs('storefront.home') ? 'text-white' : 'text-white/75' }}">হোম</span>
    </a>

    <a href="{{ route('storefront.cart') }}" class="relative flex flex-col items-center gap-1 px-2 py-1 {{ request()->routeIs('storefront.cart') ? 'text-white' : 'text-white/75' }} hover:text-white">
        <i data-lucide="receipt" class="w-5 h-5"></i>
        @if ($cartCount)
            <span class="absolute top-0 right-1 bg-white text-brand text-[9px] font-bold rounded-full w-4 h-4 grid place-items-center">{{ $cartCount }}</span>
        @endif
        <span class="text-[10px] font-medium leading-none">অর্ডার</span>
    </a>

    <a href="{{ $contactUrl ?: '#' }}" @if ($contactUrl) target="_blank" rel="noopener" @endif
       class="flex flex-col items-center gap-1 px-2 py-1 text-white/75 hover:text-white">
        <i data-lucide="message-circle" class="w-5 h-5"></i>
        <span class="text-[10px] font-medium leading-none">মেসেজ</span>
    </a>
</nav>
