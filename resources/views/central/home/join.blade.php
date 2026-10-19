@php
    // What free registration gives, stated only as strongly as the platform delivers it:
    // no certificate, messaging or guaranteed reach is offered because none exists.
    $benefits = [
        ['store', 'Your business profile', 'A public brand page with your logo, category, location and story: your business identity online.'],
        ['link', 'A link you can share', 'One permanent profile link for WhatsApp, Facebook, TikTok or your visiting card.'],
        ['search', 'Findable on Google', 'Approved profiles are public pages search engines can index. Google decides if and where they appear.'],
        ['eye', 'More visibility', 'People browsing the MetaSoft BD directory can discover your brand alongside others.'],
        ['users', 'Meet other businesses', 'Find brands and entrepreneurs in the directory and reach them through the contact details they publish.'],
        ['trophy', 'Free award entry', 'Take part in eligible MetaSoft BD award programmes at no cost, under each programme’s rules.'],
    ];
@endphp
{{-- Concept C "Join MetaSoft BD": the free profile (with what it gives) and the community. --}}
<section id="join" class="scroll-mt-20 bg-white py-9 sm:py-14" aria-labelledby="joinTitle">
    <div class="{{ $wrap }}">
        <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Join MetaSoft BD</p>
        <h2 id="joinTitle" class="{{ $h2 }}">Your business deserves to be seen</h2>
        <div class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:gap-4">
            <article id="join-benefits" class="scroll-mt-24 rounded-2xl border border-hair bg-white p-5 sm:p-6">
                <p class="text-[12px] font-extrabold uppercase tracking-[0.12em] text-golddk">Free · recommended start</p>
                <h3 class="mt-1 text-[19px] font-extrabold">Business profile</h3>
                <p class="mt-0.5 text-[13.5px] text-slate2">What free registration gives you:</p>
                <ul class="mt-3 grid gap-x-5 gap-y-3 sm:grid-cols-2">
                    @foreach($benefits as [$icon, $title, $text])
                        <li class="flex gap-2.5">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_srgb,var(--color-gold)_22%,white)] text-golddk"><x-plat.icon :name="$icon" class="w-4 h-4" /></span>
                            <span class="min-w-0"><span class="block text-[14.5px] font-extrabold leading-snug">{{ $title }}</span><span class="block text-[13px] leading-relaxed text-slate2">{{ $text }}</span></span>
                        </li>
                    @endforeach
                    {{-- The badge is set only by the MetaSoft BD team on approved brands (Super Admin → Brands → Verification, audited); registration never grants it. --}}
                    <li class="flex gap-2.5 rounded-xl bg-[#E8F1FC] p-3 sm:col-span-2">
                        <x-plat.verified size="w-8 h-8" class="mt-0.5" />
                        <span class="min-w-0"><span class="block text-[14.5px] font-extrabold leading-snug">Blue Verified badge, after review</span><span class="block text-[13px] leading-relaxed text-slate2">After your brand is approved, our team can review your business details. If they check out, the blue badge appears beside your brand name. It is never shown before that review.</span></span>
                    </li>
                </ul>
                <a href="{{ $joinUrl }}" class="mt-5 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-night text-[15px] font-bold text-white hover:bg-navy"><x-plat.icon name="user-plus" class="w-[18px] h-[18px] text-gold" /> Create Free Business Profile</a>
                <p class="mt-2.5 text-center text-[12.5px] text-slate2">Registration is free. We don’t promise search rankings, customers or sales.</p>
            </article>
            <article id="community" class="flex scroll-mt-24 flex-col justify-between gap-4 rounded-2xl bg-[#E9F9EF] p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-5 h-5'])</span>
                    <div class="min-w-0">
                        <h3 class="text-[16px] font-extrabold">MetaSoft BD Entrepreneur Community</h3>
                        <p class="mt-0.5 text-[14px] leading-relaxed text-slate2">Meet other entrepreneurs and get programme updates. Message us on WhatsApp to join.</p>
                    </div>
                </div>
                @if($whatsapp)
                    <a href="{{ $wa('Hi MetaSoft BD, I would like to join the entrepreneur community.') }}" target="_blank" rel="noopener" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] text-[14px] font-bold text-white hover:brightness-95">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-[18px] h-[18px]']) Message us on WhatsApp</a>
                @endif
            </article>
        </div>
    </div>
</section>
