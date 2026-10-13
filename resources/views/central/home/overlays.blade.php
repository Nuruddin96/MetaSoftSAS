{{-- Client-side profile data for the quick-view drawer (sample showcase content) --}}
<script type="application/json" id="profilesData">@json($profiles)</script>

{{--
    Profile dialog: a full single-brand (or entrepreneur) profile for the
    homepage's sample showcase cards — bottom sheet on phones, centred
    panel on larger screens. home.js fills it from #profilesData by the
    clicked card's key, clearing every field first, and hides each optional
    section (products, gallery, links, contact, full-profile link) when the
    profile has no data for it. Real brands open their own /brand/{slug} page.
--}}
<div id="profileDrawer" class="fixed inset-0 z-[70] hidden" role="dialog" aria-modal="true" aria-labelledby="pdName">
    <div class="absolute inset-0 bg-night/60 backdrop-blur-sm" data-close-drawer></div>
    <div class="absolute inset-x-0 bottom-0 flex max-h-[94vh] flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:max-h-[90vh] sm:w-[min(720px,calc(100vw-48px))] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-3xl">
        <div class="overflow-y-auto overscroll-contain" data-drawer-scroll>
            <div id="pdCover" class="relative h-32 shrink-0 sm:h-44">
                <span id="pdSponsored" class="absolute left-3 top-3 hidden"><x-plat.badge type="sponsored" label="Sponsored placement" size="xs" /></span>
            </div>
            <div class="relative -mt-10 px-5 pb-6 sm:px-8 sm:pb-8">
                <span id="pdLogo" class="inline-flex h-20 w-20 items-center justify-center rounded-[20px] text-2xl font-extrabold text-white ring-4 ring-white sm:h-24 sm:w-24"></span>
                <h3 id="pdName" class="mt-3 flex flex-wrap items-center gap-1.5 text-xl font-extrabold leading-tight sm:text-2xl"></h3>
                <p id="pdSubtitle" class="mt-0.5 text-sm text-slate2"></p>
                <p id="pdStatus" class="mt-2 hidden items-center gap-1.5 text-[13px] font-semibold"></p>
                <div id="pdBadges" class="mt-3 flex flex-wrap gap-1.5"></div>

                <dl id="pdFacts" class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3"></dl>

                <section class="mt-6" data-pd-section="desc">
                    <h4 class="text-[15px] font-extrabold">About</h4>
                    <p id="pdDesc" class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-slate2"></p>
                </section>
                <section class="mt-6 hidden" data-pd-section="products">
                    <h4 class="text-[15px] font-extrabold">Products & services</h4>
                    <p id="pdProducts" class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-slate2"></p>
                </section>
                <section class="mt-6 hidden" data-pd-section="gallery">
                    <h4 class="text-[15px] font-extrabold">Gallery</h4>
                    <div id="pdGallery" class="mt-3 grid grid-cols-3 gap-2"></div>
                </section>
                <section class="mt-6 hidden" data-pd-section="links">
                    <h4 class="text-[15px] font-extrabold">Website & social</h4>
                    <div id="pdLinks" class="mt-3 flex flex-wrap gap-2"></div>
                </section>
                <section class="mt-6 hidden" data-pd-section="contact">
                    <h4 class="text-[15px] font-extrabold">Contact</h4>
                    <dl id="pdContact" class="mt-3 space-y-2"></dl>
                </section>

                <div class="mt-7 grid gap-2 sm:grid-cols-2">
                    <a id="pdProfile" href="#" class="hidden items-center justify-center gap-2 rounded-xl bg-night py-3 text-sm font-bold text-white hover:bg-navy sm:col-span-2">Open full profile page <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    <button type="button" id="pdShare" class="inline-flex items-center justify-center gap-2 rounded-xl bg-leaf py-3 text-sm font-bold text-white hover:bg-leafdk"><x-plat.icon name="share" class="w-4 h-4" /> Share profile</button>
                    <a id="pdWhatsapp" href="#" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl border border-hair py-3 text-sm font-bold hover:border-leaf">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4 text-[#25D366]']) Send on WhatsApp</a>
                </div>
                <p id="pdNote" class="mt-4 text-xs text-slate2">This is a sample showcase profile. Listed brands get a permanent public profile page with products, gallery and award history.</p>
            </div>
        </div>
        <button type="button" data-close-drawer class="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-night shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
            <x-plat.icon name="x" class="w-5 h-5" /><span class="sr-only">Close profile</span>
        </button>
    </div>
</div>

{{-- Vote dialog --}}
<div id="voteModal" class="fixed inset-0 z-[70] hidden items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="vmTitle">
    <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" data-close-vote></div>
    <div class="relative w-full max-w-md rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
        <button type="button" data-close-vote class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-cloud focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf"><x-plat.icon name="x" class="w-5 h-5" /><span class="sr-only">Close</span></button>
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-leaf" id="vmCategory"></p>
        <div class="mt-3 flex items-center gap-3">
            <span id="vmLogo" class="inline-flex h-14 w-14 items-center justify-center rounded-2xl text-lg font-extrabold text-white"></span>
            <h3 id="vmTitle" class="text-xl font-extrabold"></h3>
        </div>

        <form id="voteForm" class="mt-5" novalidate>
            <label for="vmPhone" class="text-sm font-bold">Verify with your mobile number</label>
            <div class="mt-2 flex items-center overflow-hidden rounded-xl border border-hair focus-within:border-leaf focus-within:ring-2 focus-within:ring-leaf/20">
                <span class="border-r border-hair bg-cloud px-3 py-3 text-sm font-semibold text-slate2">+880</span>
                <input id="vmPhone" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="1XXXXXXXXX" maxlength="11" class="w-full px-3 py-3 text-[15px] outline-none">
            </div>
            <p id="vmError" class="mt-2 hidden text-[13px] font-semibold text-flag">Enter a valid Bangladeshi mobile number (e.g. 1712345678).</p>
            <button type="submit" class="mt-4 w-full rounded-xl bg-leaf py-3.5 text-[15px] font-bold text-white hover:bg-leafdk">Send OTP & vote</button>
            <p class="mt-3 flex items-start gap-1.5 text-xs text-slate2"><x-plat.icon name="lock" class="mt-0.5 w-3.5 h-3.5 shrink-0" /> One vote per number, per category, per day. Your number is only used to verify your vote.</p>
        </form>

        <div id="voteDone" class="mt-5 hidden">
            <div class="rounded-2xl bg-mint p-4">
                <p class="font-bold text-leafdk" id="vmDoneTitle">Thanks for supporting this brand!</p>
                <p class="mt-1 text-[13px] text-slate2" id="vmDoneText"></p>
            </div>
            <p class="mt-5 text-sm font-bold">Ask friends to vote too</p>
            <div class="mt-2 grid grid-cols-2 gap-2">
                <a id="vmShareWa" href="#" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 text-sm font-bold text-white">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4']) WhatsApp</a>
                <a id="vmShareFb" href="#" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#1877F2] py-3 text-sm font-bold text-white">@include('partials.icon', ['platform' => 'facebook', 'class' => 'w-4 h-4']) Facebook</a>
            </div>
        </div>
    </div>
</div>

<div id="toast" class="pointer-events-none fixed inset-x-0 bottom-24 z-[80] flex justify-center px-4 lg:bottom-8" aria-live="polite">
    <span class="translate-y-3 rounded-full bg-night px-4 py-2.5 text-sm font-semibold text-white opacity-0 shadow-xl transition duration-200"></span>
</div>

<script>window.__platformPreview = @json($preview);</script>
