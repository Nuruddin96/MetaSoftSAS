{{-- Client-side profile data for the quick-view drawer (sample showcase content) --}}
<script type="application/json" id="profilesData">@json($profiles)</script>

{{-- Profile quick view: bottom sheet on mobile, side panel on desktop --}}
<div id="profileDrawer" class="fixed inset-0 z-[70] hidden" role="dialog" aria-modal="true" aria-labelledby="pdName">
    <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" data-close-drawer></div>
    <div class="absolute inset-x-0 bottom-0 max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white shadow-2xl sm:inset-x-auto sm:inset-y-0 sm:right-0 sm:max-h-none sm:w-[420px] sm:rounded-none">
        <div id="pdCover" class="relative h-32">
            <button type="button" data-close-drawer class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-night focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                <x-plat.icon name="x" class="w-5 h-5" /><span class="sr-only">Close</span>
            </button>
            <span id="pdSponsored" class="absolute left-3 top-3 hidden"><x-plat.badge type="sponsored" label="Sponsored placement" size="xs" /></span>
        </div>
        <div class="relative -mt-9 px-6 pb-8">
            <span id="pdLogo" class="inline-flex h-[72px] w-[72px] items-center justify-center rounded-[20px] text-2xl font-extrabold text-white ring-4 ring-white"></span>
            <h3 id="pdName" class="mt-3 flex items-center gap-1.5 text-xl font-extrabold"></h3>
            <p id="pdSubtitle" class="text-sm text-slate2"></p>
            <div id="pdBadges" class="mt-3 flex flex-wrap gap-1.5"></div>
            <p id="pdDesc" class="mt-4 text-[15px] leading-relaxed text-slate2"></p>
            <dl id="pdFacts" class="mt-5 grid grid-cols-3 gap-2"></dl>
            <div class="mt-6 grid grid-cols-2 gap-2">
                <button type="button" id="pdShare" class="inline-flex items-center justify-center gap-2 rounded-xl bg-leaf py-3 text-sm font-bold text-white hover:bg-leafdk"><x-plat.icon name="share" class="w-4 h-4" /> Share profile</button>
                <a id="pdWhatsapp" href="#" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl border border-hair py-3 text-sm font-bold hover:border-leaf">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4 text-[#25D366]']) Send on WhatsApp</a>
            </div>
            <p class="mt-4 text-xs text-slate2">Every brand and entrepreneur gets a permanent public profile page — full profiles with products, story and award history are rolling out next.</p>
        </div>
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
